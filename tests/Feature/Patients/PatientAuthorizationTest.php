<?php

use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('a patient can view their own profile', function () {
    $user = User::factory()->patient()->create();
    Patient::factory()->linkedTo($user)->create([
        'full_name' => 'Jane Doe',
        'date_of_birth' => '1990-05-15',
        'gender' => 'female',
        'occupation' => 'Engineer',
    ]);

    $this->actingAs($user)
        ->getJson('/api/patient/profile')
        ->assertOk()
        ->assertJsonPath('data.full_name', 'Jane Doe')
        ->assertJsonPath('data.gender', 'female')
        ->assertJsonPath('data.occupation', 'Engineer')
        ->assertJsonPath('data.date_of_birth', '1990-05-15');
});

test('a patient can update their own profile', function () {
    $user = User::factory()->patient()->create();
    Patient::factory()->linkedTo($user)->create();

    $this->actingAs($user)
        ->patchJson('/api/patient/profile', [
            'full_name' => 'Updated Name',
            'date_of_birth' => '1985-03-20',
            'occupation' => 'Doctor',
            'gender' => 'male',
            'address' => '456 Main Ave, Manila',
            'contact_email' => 'updated@example.com',
            'phone' => '09189998888',
        ])
        ->assertOk()
        ->assertJsonPath('data.full_name', 'Updated Name')
        ->assertJsonPath('data.gender', 'male')
        ->assertJsonPath('data.occupation', 'Doctor');

    $patient = $user->patient->fresh();
    expect($patient->full_name)->toBe('Updated Name')
        ->and($patient->gender)->toBe('male')
        ->and($patient->occupation)->toBe('Doctor')
        ->and($patient->address)->toBe('456 Main Ave, Manila')
        ->and($patient->contact_email)->toBe('updated@example.com')
        ->and($patient->phone)->toBe('09189998888');
});

test('a patient cannot view another patient profile', function () {
    // The endpoint returns only the authenticated user's own patient record.
    // Patient A's request returns their own data, never Patient B's.
    $userA = User::factory()->patient()->create();
    Patient::factory()->linkedTo($userA)->create(['full_name' => 'Patient A']);
    $userB = User::factory()->patient()->create();
    Patient::factory()->linkedTo($userB)->create(['full_name' => 'Patient B']);

    $this->actingAs($userA)
        ->getJson('/api/patient/profile')
        ->assertOk()
        ->assertJsonPath('data.full_name', 'Patient A')
        ->assertJsonMissing(['full_name' => 'Patient B']);
});

test('a patient cannot update another patient profile', function () {
    // The endpoint updates only the authenticated user's own patient record.
    $userA = User::factory()->patient()->create();
    Patient::factory()->linkedTo($userA)->create(['full_name' => 'Patient A']);
    $userB = User::factory()->patient()->create();
    Patient::factory()->linkedTo($userB)->create(['full_name' => 'Patient B']);

    $this->actingAs($userA)
        ->patchJson('/api/patient/profile', ['full_name' => 'Updated A'])
        ->assertOk();

    expect($userA->patient->fresh()->full_name)->toBe('Updated A')
        ->and($userB->patient->fresh()->full_name)->toBe('Patient B');
});

test('unauthenticated request to patient profile returns 401', function () {
    $this->getJson('/api/patient/profile')->assertUnauthorized();
    $this->patchJson('/api/patient/profile', ['full_name' => 'Test'])->assertUnauthorized();
});

test('patient profile update rejects invalid gender', function () {
    $user = User::factory()->patient()->create();
    Patient::factory()->linkedTo($user)->create();

    $this->actingAs($user)
        ->patchJson('/api/patient/profile', ['gender' => 'invalid'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['gender']);
});
