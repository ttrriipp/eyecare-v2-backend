<?php

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Appointments\Widgets\AppointmentCalendarWidget;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\RoleSeeder;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
});

test('appointments exposes a calendar resource page without a second navigation item', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    expect(AppointmentResource::getPages())->toHaveKey('calendar')
        ->and(AppointmentResource::getNavigationItems())->toHaveCount(1);

    $this->get(AppointmentResource::getUrl('calendar'))
        ->assertSuccessful()
        ->assertSee('Appointment Calendar');
});

test('appointment list exposes a calendar navigation action', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->assertActionExists('calendar');
});

test('calendar events include capacity blocking appointments but omit terminal records', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $type = AppointmentType::factory()->create();

    $scheduled = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'appointment_type_id' => $type->id,
        'scheduled_at' => now()->addDay()->setTime(10, 0),
    ]);
    $checkedIn = Appointment::factory()->checkedIn()->create([
        'patient_id' => $patient->id,
        'appointment_type_id' => $type->id,
        'scheduled_at' => now()->addDay()->setTime(11, 0),
    ]);
    $cancelled = Appointment::factory()->cancelled()->create([
        'patient_id' => $patient->id,
        'appointment_type_id' => $type->id,
        'scheduled_at' => now()->addDay()->setTime(12, 0),
    ]);
    $noShow = Appointment::factory()->noShow()->create([
        'patient_id' => $patient->id,
        'appointment_type_id' => $type->id,
        'scheduled_at' => now()->addDay()->setTime(13, 0),
    ]);

    $this->actingAs($staff);

    $widget = Livewire::test(AppointmentCalendarWidget::class)->instance();
    $method = new ReflectionMethod($widget, 'getEvents');
    $method->setAccessible(true);

    $events = $method->invoke($widget, new FetchInfo([
        'startStr' => now()->startOfDay()->toIso8601String(),
        'endStr' => now()->addDays(3)->endOfDay()->toIso8601String(),
    ]));

    expect($events->pluck('id')->all())
        ->toContain($scheduled->id, $checkedIn->id)
        ->not->toContain($cancelled->id, $noShow->id);
});

test('calendar event mapping does not mutate the appointment start time', function () {
    $appointment = Appointment::factory()->create([
        'duration_minutes' => 45,
        'scheduled_at' => now()->addDay()->setTime(10, 0),
    ]);

    $scheduledAt = $appointment->scheduled_at->toDateTimeString();

    $event = $appointment->toCalendarEvent();

    expect($appointment->scheduled_at->toDateTimeString())->toBe($scheduledAt)
        ->and($event->getEnd()->toDateTimeString())
        ->toBe($appointment->scheduled_at->copy()->addMinutes(45)->toDateTimeString());
});
