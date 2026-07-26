<?php

use App\Models\Appointment;
use App\Models\PatientIntake;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
});

test('each appointment exposes only its own intake', function () {
    $user = User::factory()->patient()->create();
    $appointment1 = Appointment::factory()->create(['patient_id' => $user->patient->id]);
    $appointment2 = Appointment::factory()->create(['patient_id' => $user->patient->id]);

    PatientIntake::factory()->create([
        'patient_id' => $user->patient->id,
        'appointment_id' => $appointment1->id,
        'chief_complaint' => 'Blurred vision',
    ]);
    PatientIntake::factory()->create([
        'patient_id' => $user->patient->id,
        'appointment_id' => $appointment2->id,
        'chief_complaint' => 'Headache',
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/appointments/{$appointment1->id}/intake")
        ->assertOk()
        ->assertJsonPath('data.chief_complaint', 'Blurred vision');
});

test('submitted records cannot be edited', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create(['patient_id' => $user->patient->id]);
    $intake = PatientIntake::factory()->submitted()->create([
        'patient_id' => $user->patient->id,
        'appointment_id' => $appointment->id,
    ]);

    $this->actingAs($user)
        ->putJson("/api/v1/appointments/{$appointment->id}/intake", [
            'chief_complaint' => 'Updated complaint',
        ])
        ->assertUnprocessable();
});

test('verified records are immutable', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create(['patient_id' => $user->patient->id]);
    $intake = PatientIntake::factory()->verified()->create([
        'patient_id' => $user->patient->id,
        'appointment_id' => $appointment->id,
    ]);

    $this->actingAs($user)
        ->putJson("/api/v1/appointments/{$appointment->id}/intake", [
            'chief_complaint' => 'Should not work',
        ])
        ->assertUnprocessable();
});

test('cross-patient intake access is denied', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA)
        ->putJson("/api/v1/appointments/{$appointment->id}/intake", [
            'chief_complaint' => 'Hacked',
        ])
        ->assertForbidden();
});
