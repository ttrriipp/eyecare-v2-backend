<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

// --- Account-Only Routes (no link required) ---

test('unlinked account can access me endpoint', function () {
    // Create a patient-role user without a linked Patient record
    $user = User::factory()->create(['role_id' => Role::where('name', 'patient')->first()->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk();
});

test('unlinked account can logout', function () {
    $user = User::factory()->create(['role_id' => Role::where('name', 'patient')->first()->id]);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/logout')
        ->assertOk();
});

// --- Clinical Routes (link required) ---

test('unlinked account cannot access prescriptions', function () {
    $user = User::factory()->create(['role_id' => Role::where('name', 'patient')->first()->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/prescriptions')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'ACTIVE_PATIENT_LINK_REQUIRED');
});

test('unlinked account cannot access appointments', function () {
    $user = User::factory()->create(['role_id' => Role::where('name', 'patient')->first()->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/appointments')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'ACTIVE_PATIENT_LINK_REQUIRED');
});

test('unlinked account cannot access eyewear', function () {
    $user = User::factory()->create(['role_id' => Role::where('name', 'patient')->first()->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/eyewear')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'ACTIVE_PATIENT_LINK_REQUIRED');
});

test('unlinked account cannot access conversation', function () {
    $user = User::factory()->create(['role_id' => Role::where('name', 'patient')->first()->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/conversation')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'ACTIVE_PATIENT_LINK_REQUIRED');
});

test('linked account can access clinical routes', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/prescriptions')
        ->assertOk();
});

test('unauthenticated request returns 401', function () {
    $this->getJson('/api/v1/prescriptions')
        ->assertUnauthorized();
});
