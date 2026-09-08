<?php

use App\Filament\Resources\AppointmentRequests\Pages\ListAppointmentRequests;
use App\Filament\Resources\AppointmentRequests\Pages\ReviewAppointmentRequestSchedule;
use App\Models\Appointment;
use App\Models\AppointmentRequest;
use App\Models\AppointmentType;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\ClinicHoursSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-10 08:00:00');
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    $this->seed(ClinicHoursSeeder::class);
});

afterEach(fn () => Carbon::setTestNow());

function filamentRebookingFixture(): array
{
    $staff = User::factory()->staff()->create();
    $patientAccount = User::factory()->patient()->create();
    $optometrist = User::factory()->optometrist()->create();
    $type = AppointmentType::query()->where('name', 'New Patient')->firstOrFail();
    $appointment = Appointment::factory()->create([
        'patient_id' => $patientAccount->patient->id,
        'appointment_type_id' => $type->id,
        'duration_minutes' => $type->duration_minutes,
        'optometrist_id' => $optometrist->id,
        'scheduled_at' => '2026-07-13 10:00:00',
    ]);
    $request = AppointmentRequest::factory()
        ->rebookingFor($appointment)
        ->create([
            'user_id' => $patientAccount->id,
            'scheduled_at' => '2026-07-14 11:00:00',
            'expires_at' => now()->addDay(),
        ]);

    return compact('staff', 'patientAccount', 'optometrist', 'type', 'appointment', 'request');
}

test('request queue identifies rebooking requests without a current appointment column', function (): void {
    $fixture = filamentRebookingFixture();

    $this->actingAs($fixture['staff']);

    Livewire::test(ListAppointmentRequests::class)
        ->assertSee('Rebooking')
        ->assertTableColumnDoesNotExist('appointment.scheduled_at');
});

test('rebooking review derives and locks appointment details', function (): void {
    $fixture = filamentRebookingFixture();

    $this->actingAs($fixture['staff']);

    Livewire::test(ReviewAppointmentRequestSchedule::class, [
        'record' => $fixture['request']->getRouteKey(),
    ])
        ->assertSet('appointmentTypeId', $fixture['type']->id)
        ->assertSet('durationMinutes', $fixture['appointment']->duration_minutes)
        ->assertSet('optometristId', $fixture['optometrist']->id)
        ->set('durationMinutes', 15)
        ->assertSet('durationMinutes', $fixture['appointment']->duration_minutes)
        ->set('optometristId', null)
        ->assertSet('optometristId', $fixture['optometrist']->id)
        ->assertSee('Current appointment')
        ->assertSee($fixture['appointment']->scheduled_at->format('M j, Y \a\t g:i A'));
});

test('rebooking availability ignores the appointment being moved', function (): void {
    $fixture = filamentRebookingFixture();
    $fixture['request']->update([
        'scheduled_at' => '2026-07-13 10:15:00',
        'original_scheduled_at' => $fixture['appointment']->scheduled_at,
    ]);

    $this->actingAs($fixture['staff']);

    Livewire::test(ListAppointmentRequests::class)
        ->assertSee('Available')
        ->assertSee('Primary');
});
