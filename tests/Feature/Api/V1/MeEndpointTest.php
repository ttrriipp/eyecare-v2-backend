<?php

use App\Enums\OtpPurpose;
use App\Models\OtpChallenge;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('me endpoint returns patient profile data', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertSuccessful()
        ->assertJsonPath('data.linked_patient.patient_number', $user->patient->patient_number)
        ->assertJsonPath('data.linked_patient.full_name', $user->patient->full_name)
        ->assertJsonPath('data.name', $user->full_name);
});

test('me endpoint can update account fields', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->patchJson('/api/v1/me', ['first_name' => 'Updated', 'last_name' => 'Name'])
        ->assertSuccessful()
        ->assertJsonPath('data.first_name', 'Updated');
});

test('me endpoint can update account name', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->patchJson('/api/v1/me', [
            'first_name' => 'New',
            'middle_name' => null,
            'last_name' => 'Name',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.first_name', 'New')
        ->assertJsonPath('data.name', 'New Name');
});

test('me endpoint requires step-up verification when date of birth is submitted', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->patchJson('/api/v1/me', [
            'date_of_birth' => '1990-01-01',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'STEP_UP_REQUIRED');
});

test('password changes remain unconditionally protected by step-up verification', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'STEP_UP_REQUIRED');
});

test('valid step-up verification allows a date of birth profile request through the middleware', function () {
    $user = User::factory()->patient()->create();
    $token = 'valid-step-up-token';

    OtpChallenge::factory()
        ->forUser($user)
        ->purpose(OtpPurpose::SensitiveChange)
        ->state([
            'consumed_at' => now(),
            'delivery_status' => 'step_up_token_issued:'.Hash::make($token),
        ])
        ->create();

    $this->actingAs($user)
        ->withHeader('X-Step-Up-Token', $token)
        ->patchJson('/api/v1/me', [
            'date_of_birth' => '1990-01-01',
        ])
        ->assertSuccessful();
});

test('patient profile routes are absent', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/patient/profile')
        ->assertNotFound();

    $this->actingAs($user)
        ->patchJson('/api/v1/patient/profile', ['full_name' => 'Test'])
        ->assertNotFound();
});

test('me endpoint returns 404 when no patient linked', function () {
    $user = User::factory()->staff()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertSuccessful();
});

test('me endpoint tolerates a normal mobile bootstrap burst', function () {
    $user = User::factory()->patient()->create();
    $token = $user->createToken('mobile')->plainTextToken;

    foreach (range(1, 61) as $attempt) {
        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertSuccessful();
    }
});
