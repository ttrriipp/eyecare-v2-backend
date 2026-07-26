<?php

use App\Models\Appointment;
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

test('appointments list is paginated', function () {
    $user = User::factory()->patient()->create();
    Appointment::factory()->count(20)->create(['patient_id' => $user->patient->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/appointments')
        ->assertOk()
        ->assertJsonStructure([
            'data',
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
});

test('every appointment is linked-patient scoped', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();
    Appointment::factory()->count(3)->create(['patient_id' => $userA->patient->id]);
    Appointment::factory()->count(2)->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA)
        ->getJson('/api/v1/appointments')
        ->assertOk()
        ->assertJsonPath('meta.total', 3);
});

test('patient resource excludes staff notes', function () {
    $user = User::factory()->patient()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $user->patient->id,
        'staff_notes' => 'Internal note',
        'contact_notes' => 'Patient note',
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/appointments/{$appointment->id}")
        ->assertOk()
        ->assertJsonMissing(['staff_notes' => 'Internal note'])
        ->assertJsonPath('data.contact_notes', 'Patient note');
});

test('patient resource excludes optometrist id', function () {
    $user = User::factory()->patient()->create();
    $optometrist = User::factory()->optometrist()->create();
    $appointment = Appointment::factory()->create([
        'patient_id' => $user->patient->id,
        'optometrist_id' => $optometrist->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/appointments/{$appointment->id}")
        ->assertOk();

    $optometristData = $response->json('data.assigned_optometrist');
    expect($optometristData)->not->toHaveKey('id')
        ->and($optometristData)->toHaveKey('name');
});

test('cross-patient substitution returns error', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();
    $appointmentB = Appointment::factory()->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA)
        ->getJson("/api/v1/appointments/{$appointmentB->id}")
        ->assertNotFound();
});
