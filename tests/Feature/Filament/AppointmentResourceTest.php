<?php

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\AppointmentType;
use App\Models\Encounter;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('appointment table shows the populated appointment type', function () {
    $staff = User::factory()->staff()->create();
    $appointmentType = AppointmentType::factory()->create([
        'name' => 'Routine Vision Review',
    ]);
    $appointment = Appointment::factory()->create([
        'appointment_type_id' => $appointmentType->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->assertCanSeeTableRecords([$appointment])
        ->assertSee('Routine Vision Review')
        ->assertSee('Appointment Type')
        ->assertDontSee('Visit reason');
});

test('appointment resource has no billing relation manager', function () {
    expect(AppointmentResource::getRelations())->toBeEmpty();
});

test('appointment status is read only on the edit form', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditAppointment::class, ['record' => $appointment->getRouteKey()])
        ->assertFormFieldDoesNotExist('appointment_status_id')
        ->assertSee('Scheduled');
});

test('appointment table has no generic lifecycle advance actions', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->assertActionDoesNotExist(TestAction::make('advance')->table($appointment))
        ->assertActionDoesNotExist(TestAction::make('bulk_confirm')->table()->bulk());
});

test('checked in appointment links to its encounter instead of exposing generic completion', function () {
    $staff = User::factory()->staff()->create();
    $checkedIn = AppointmentStatus::query()->firstOrCreate(['name' => 'checked_in']);
    $appointment = Appointment::factory()->create([
        'appointment_status_id' => $checkedIn->id,
        'checked_in_at' => now(),
    ]);
    $encounter = Encounter::factory()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ListAppointments::class)
        ->assertActionVisible(TestAction::make('openEncounter')->table($appointment))
        ->assertActionHidden(TestAction::make('assign')->table($appointment))
        ->assertActionHasUrl(
            TestAction::make('openEncounter')->table($appointment),
            route('filament.admin.resources.encounters.edit', ['record' => $encounter]),
        );
});
