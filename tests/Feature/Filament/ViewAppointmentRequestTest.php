<?php

use App\Filament\Resources\AppointmentRequests\Pages\ViewAppointmentRequest;
use App\Models\AppointmentRequest;
use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can link an unlinked request to a patient from the detail page', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->withSnapshot()->create(['patient_id' => null]);
    $patient = Patient::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertActionVisible('linkToPatient')
        ->assertActionHidden('accept')
        ->callAction('linkToPatient', ['patient_id' => $patient->id])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($request->fresh()->patient_id)->toBe($patient->id);
});

test('accepting a linked request from the detail page does not error and creates an appointment', function () {
    $this->seed(AppointmentStatusSeeder::class);
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $request = AppointmentRequest::factory()->create(['patient_id' => $patient->id]);
    $appointmentType = AppointmentType::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertActionVisible('accept')
        ->assertActionHidden('linkToPatient')
        ->callAction('accept', ['appointment_type_id' => $appointmentType->id])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($request->fresh()->status->value)->toBe('accepted');
});

test('rejecting a request from the detail page does not error', function () {
    $staff = User::factory()->staff()->create();
    $request = AppointmentRequest::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ViewAppointmentRequest::class, ['record' => $request->getRouteKey()])
        ->callAction('reject', ['reason' => 'Patient no longer needs this slot.'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($request->fresh()->status->value)->toBe('rejected');
});
