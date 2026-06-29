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

/**
 * Invoke a protected method for testing internal widget logic.
 */
function invokeWidgetMethod(object $widget, string $method, array $args): mixed
{
    $reflection = new ReflectionMethod($widget, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($widget, ...$args);
}

// ─── Conflict helper ───────────────────────────────────────────────────────────

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

// ─── Create prefill ─────────────────────────────────────────────────────────────

test('create appointment page prefills scheduled_at from the query string', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::withQueryParams(['scheduled_at' => '2026-07-15 14:00:00'])
        ->test(CreateAppointment::class)
        ->assertSet('data.scheduled_at', '2026-07-15 14:00:00');
});

// ─── Reschedule validation (drag guard) ─────────────────────────────────────────

test('validateReschedule accepts a valid future slot', function () {
    $pending = AppointmentStatus::query()->where('name', 'pending')->value('id');
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $pending,
        'scheduled_at' => now()->addDay()->setTime(9, 0),
    ]);

    $result = invokeWidgetMethod(new AppointmentCalendarWidget, 'validateReschedule', [
        $appointment, now()->addDays(2)->setTime(14, 0),
    ]);

    expect($result)->toBeTrue();
});

test('validateReschedule rejects a completed appointment', function () {
    $completed = AppointmentStatus::query()->where('name', 'completed')->value('id');
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $completed,
        'scheduled_at' => now()->subDay()->setTime(9, 0),
    ]);

    $result = invokeWidgetMethod(new AppointmentCalendarWidget, 'validateReschedule', [
        $appointment, now()->addDays(2)->setTime(10, 0),
    ]);

    expect($result)->toBeFalse();
});

test('validateReschedule rejects a past date', function () {
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->value('id');
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $confirmed,
        'scheduled_at' => now()->addDay()->setTime(10, 0),
    ]);

    $result = invokeWidgetMethod(new AppointmentCalendarWidget, 'validateReschedule', [
        $appointment, now()->subDay()->setTime(11, 0),
    ]);

    expect($result)->toBeFalse();
});

test('validateReschedule rejects a conflicting slot', function () {
    $confirmed = AppointmentStatus::query()->where('name', 'confirmed')->value('id');
    $existing = Appointment::factory()->create([
        'appointment_status_id' => $confirmed,
        'scheduled_at' => now()->addDays(2)->setTime(14, 0),
    ]);
    $moving = Appointment::factory()->create([
        'appointment_status_id' => $confirmed,
        'scheduled_at' => now()->addDay()->setTime(9, 0),
    ]);

    $result = invokeWidgetMethod(new AppointmentCalendarWidget, 'validateReschedule', [
        $moving, $existing->scheduled_at->copy()->addMinutes(10),
    ]);

    expect($result)->toBeFalse();
});

// ─── Reschedule execution ────────────────────────────────────────────────────────

test('performReschedule moves the appointment and marks it rescheduled', function () {
    Http::fake();
    $this->seed(NotificationStatusSeeder::class);

    $pending = AppointmentStatus::query()->where('name', 'pending')->value('id');
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $pending,
        'scheduled_at' => now()->addDay()->setTime(9, 0),
    ]);
    $newStart = now()->addDays(2)->setTime(14, 0);

    invokeWidgetMethod(new AppointmentCalendarWidget, 'performReschedule', [$appointment, $newStart]);

    $appointment->refresh();
    expect($appointment->status->name)->toBe('rescheduled')
        ->and($appointment->scheduled_at->format('Y-m-d H:i'))->toBe($newStart->format('Y-m-d H:i'));
});

// ─── Confirmation + view toggles ──────────────────────────────────────────────────

test('the calendar exposes a confirmation action for rescheduling', function () {
    expect((new AppointmentCalendarWidget)->confirmRescheduleAction()->getName())->toBe('confirmReschedule');
});

test('the reschedule confirmation action mounts on the calendar widget', function () {
    $this->actingAs(User::factory()->admin()->create());

    $pending = AppointmentStatus::query()->where('name', 'pending')->value('id');
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $pending,
        'scheduled_at' => now()->addDay()->setTime(9, 0),
    ]);

    Livewire::test(AppointmentCalendarWidget::class)
        ->mountAction('confirmReschedule', [
            'appointmentId' => $appointment->id,
            'newStart' => now()->addDays(2)->setTime(14, 0)->toIso8601String(),
        ])
        ->assertActionMounted('confirmReschedule');
});

test('the calendar exposes month, week, and day view toggles', function () {
    $options = (new AppointmentCalendarWidget)->getOptions();

    expect($options['headerToolbar']['end'])
        ->toContain('dayGridMonth')
        ->toContain('timeGridWeek')
        ->toContain('timeGridDay');
});
