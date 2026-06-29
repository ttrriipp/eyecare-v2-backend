<?php

use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Widgets\AppointmentCalendarWidget;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(AppointmentStatusSeeder::class);
});

test('conflictsWith detects an appointment within 30 minutes', function () {
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->value('id');
    Appointment::factory()->create([
        'appointment_status_id' => $confirmed,
        'scheduled_at' => '2026-07-01 10:00:00',
    ]);

    expect(Appointment::conflictsWith(Carbon::parse('2026-07-01 10:20:00')))->toBeTrue()
        ->and(Appointment::conflictsWith(Carbon::parse('2026-07-01 11:00:00')))->toBeFalse();
});

test('conflictsWith ignores the excluded appointment id', function () {
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->value('id');
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $confirmed,
        'scheduled_at' => '2026-07-01 10:00:00',
    ]);

    expect(Appointment::conflictsWith(Carbon::parse('2026-07-01 10:00:00'), $appointment->id))->toBeFalse();
});

test('conflictsWith ignores cancelled appointments', function () {
    $cancelled = AppointmentStatus::query()->where('name', 'cancelled')->value('id');
    Appointment::factory()->create([
        'appointment_status_id' => $cancelled,
        'scheduled_at' => '2026-07-01 10:00:00',
    ]);

    expect(Appointment::conflictsWith(Carbon::parse('2026-07-01 10:10:00')))->toBeFalse();
});

test('create appointment page prefills scheduled_at from the query string', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::withQueryParams(['scheduled_at' => '2026-07-15 14:00:00'])
        ->test(CreateAppointment::class)
        ->assertSet('data.scheduled_at', '2026-07-15 14:00:00');
});

/**
 * Invoke a protected/private method for testing internal widget logic.
 */
function invokeWidgetMethod(object $widget, string $method, array $args): mixed
{
    $reflection = new ReflectionMethod($widget, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($widget, ...$args);
}

test('dragging a pending appointment to a free future slot reschedules it', function () {
    Http::fake();
    $this->seed(NotificationStatusSeeder::class);

    $pending = AppointmentStatus::query()->where('name', 'pending')->value('id');
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $pending,
        'scheduled_at' => now()->addDay()->setTime(9, 0),
    ]);
    $newStart = now()->addDays(2)->setTime(14, 0);

    $widget = new AppointmentCalendarWidget;
    $result = invokeWidgetMethod($widget, 'attemptReschedule', [$appointment, $newStart]);

    expect($result)->toBeTrue();

    $appointment->refresh();
    expect($appointment->status->name)->toBe('rescheduled')
        ->and($appointment->scheduled_at->format('Y-m-d H:i'))->toBe($newStart->format('Y-m-d H:i'));
});

test('dragging a completed appointment is rejected and leaves it unchanged', function () {
    $completed = AppointmentStatus::query()->where('name', 'completed')->value('id');
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $completed,
        'scheduled_at' => now()->subDay()->setTime(9, 0),
    ]);
    $original = $appointment->scheduled_at->toDateTimeString();

    $widget = new AppointmentCalendarWidget;
    $result = invokeWidgetMethod($widget, 'attemptReschedule', [$appointment, now()->addDays(2)->setTime(10, 0)]);

    expect($result)->toBeFalse();
    expect($appointment->fresh()->scheduled_at->toDateTimeString())->toBe($original)
        ->and($appointment->fresh()->status->name)->toBe('completed');
});

test('dragging into a conflicting slot is rejected', function () {
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->value('id');

    $existing = Appointment::factory()->create([
        'appointment_status_id' => $confirmed,
        'scheduled_at' => now()->addDays(2)->setTime(14, 0),
    ]);
    $moving = Appointment::factory()->create([
        'appointment_status_id' => $confirmed,
        'scheduled_at' => now()->addDay()->setTime(9, 0),
    ]);
    $original = $moving->scheduled_at->toDateTimeString();

    $widget = new AppointmentCalendarWidget;
    $result = invokeWidgetMethod($widget, 'attemptReschedule', [$moving, $existing->scheduled_at->copy()->addMinutes(10)]);

    expect($result)->toBeFalse();
    expect($moving->fresh()->scheduled_at->toDateTimeString())->toBe($original);
});

test('the calendar exposes month, week, and day view toggles', function () {
    $options = (new AppointmentCalendarWidget)->getOptions();

    expect($options['headerToolbar']['end'])
        ->toContain('dayGridMonth')
        ->toContain('timeGridWeek')
        ->toContain('timeGridDay');
});

test('dragging an appointment to a past date is rejected', function () {
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->value('id');
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $confirmed,
        'scheduled_at' => now()->addDay()->setTime(10, 0),
    ]);
    $original = $appointment->scheduled_at->toDateTimeString();

    $widget = new AppointmentCalendarWidget;
    // Yesterday — before today's start, so it must be rejected.
    $result = invokeWidgetMethod($widget, 'attemptReschedule', [$appointment, now()->subDay()->setTime(11, 0)]);

    expect($result)->toBeFalse()
        ->and($appointment->fresh()->scheduled_at->toDateTimeString())->toBe($original);
});
