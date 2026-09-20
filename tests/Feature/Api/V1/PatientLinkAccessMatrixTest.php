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

test('unlinked account cannot access accessory ordering surfaces', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/accessories')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'ACTIVE_PATIENT_LINK_REQUIRED');

    $this->actingAs($user)
        ->getJson('/api/v1/accessory-order-requests')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'ACTIVE_PATIENT_LINK_REQUIRED');
});

test('unlinked account can access its account-owned conversation', function () {
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('name', Role::Patient)->firstOrFail());

    $this->actingAs($user)
        ->getJson('/api/v1/conversation')
        ->assertCreated()
        ->assertJsonPath('data.access_level', 'general_inquiry')
        ->assertJsonPath('data.capabilities.can_upload_attachments', false);
});

test('linked account can access clinical routes', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/prescriptions')
        ->assertOk();
});

test('identity review does not suspend linked clinical access', function (): void {
    $user = User::factory()->patient()->create();
    $user->patient->forceFill([
        'identity_review_required' => true,
        'identity_review_required_at' => now(),
    ])->saveQuietly();

    $this->actingAs($user)
        ->getJson('/api/v1/prescriptions')
        ->assertOk();
});

test('unauthenticated request returns 401', function () {
    $this->getJson('/api/v1/prescriptions')
        ->assertUnauthorized();
});
