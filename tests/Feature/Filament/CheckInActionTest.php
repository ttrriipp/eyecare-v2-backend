<?php

use App\Enums\EncounterStatus;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Encounter;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
});

test('check-in action uses CheckInAppointment and creates encounter', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    // Confirm the appointment first
    $confirmed = AppointmentStatus::query()->where('name', 'scheduled')->firstOrFail();
    $appointment->update(['appointment_status_id' => $confirmed->id]);

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->callTableAction('checkIn', $appointment)
        ->assertHasNoTableActionErrors();

    $appointment->refresh();
    expect($appointment->status->name)->toBe('checked_in');

    $this->assertDatabaseHas('encounters', [
        'appointment_id' => $appointment->id,
        'status' => 'planned',
    ]);
});

test('optometrist can start and complete encounter', function () {
    $optometrist = User::factory()->optometrist()->create();
    $appointment = Appointment::factory()->create();
    $checkedIn = AppointmentStatus::query()->where('name', 'checked_in')->firstOrFail();
    $appointment->update(['appointment_status_id' => $checkedIn->id]);
    $encounter = Encounter::factory()->create([
        'status' => EncounterStatus::Planned,
        'appointment_id' => $appointment->id,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertSee('Start Visit')
        ->callAction('startEncounter', [
            'optometrist_id' => $optometrist->id,
        ])
        ->assertHasNoActionErrors();

    $encounter->refresh();
    expect($encounter->status)->toBe(EncounterStatus::InProgress);
});

test('receptionist cannot start encounter', function () {
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Planned]);

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertDontSee('Start Visit');
});

test('receptionist cannot complete encounter', function () {
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);
    $encounter = Encounter::factory()->inProgress()->create();

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertDontSee('Complete Encounter');
});

test('check in replaces mark arrived in appointment table', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->assertDontSee('Mark Arrived');
});
