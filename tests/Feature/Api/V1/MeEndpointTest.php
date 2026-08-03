<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('me endpoint returns patient profile data', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertSuccessful()
        ->assertJsonPath('data.patient_number', $user->patient->patient_number)
        ->assertJsonPath('data.full_name', $user->patient->full_name)
        ->assertJsonPath('data.name', $user->full_name);
});

test('me endpoint can update account fields', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->patchJson('/api/v1/me', ['first_name' => 'Updated', 'last_name' => 'Name'])
        ->assertSuccessful()
        ->assertJsonPath('data.first_name', 'Updated');
});

test('me endpoint can update patient fields', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->patchJson('/api/v1/me', [
            'first_name' => 'New',
            'last_name' => 'Name',
            'occupation' => 'Engineer',
            'gender' => 'male',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.first_name', 'New')
        ->assertJsonPath('data.occupation', 'Engineer');
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
