<?php

use App\Models\AppointmentRequest;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-10 08:00:00');
    $this->seed(RoleSeeder::class);
});

afterEach(fn () => Carbon::setTestNow());

// --- Listing ---

test('user can list their own appointment requests', function () {
    $user = User::factory()->patient()->create();

    AppointmentRequest::factory()->count(3)->create(['user_id' => $user->id]);
    AppointmentRequest::factory()->count(2)->create(); // Other users

    $response = $this->actingAs($user)
        ->getJson('/api/v1/appointment-requests');

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

// --- Detail ---

test('user can view their own request', function () {
    $user = User::factory()->patient()->create();
    $request = AppointmentRequest::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/appointment-requests/{$request->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $request->id);
});

test('user cannot view another users request', function () {
    $user = User::factory()->patient()->create();
    $otherRequest = AppointmentRequest::factory()->create();

    $this->actingAs($user)
        ->getJson("/api/v1/appointment-requests/{$otherRequest->id}")
        ->assertNotFound();
});

// --- Cancellation ---

test('user can cancel their own pending request', function () {
    $user = User::factory()->patient()->create();
    $request = AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'status' => 'pending',
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/appointment-requests/{$request->id}/cancel");

    $response->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

test('user cannot cancel a non-pending request', function () {
    $user = User::factory()->patient()->create();
    $request = AppointmentRequest::factory()->accepted()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/appointment-requests/{$request->id}/cancel");

    $response->assertUnprocessable();
});

test('user cannot cancel another users request', function () {
    $user = User::factory()->patient()->create();
    $otherRequest = AppointmentRequest::factory()->create();

    $this->actingAs($user)
        ->postJson("/api/v1/appointment-requests/{$otherRequest->id}/cancel")
        ->assertNotFound();
});

// --- Response Safety ---

test('response does not expose internal notes or other accounts', function () {
    $user = User::factory()->patient()->create();
    $request = AppointmentRequest::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/appointment-requests/{$request->id}");

    $data = $response->json('data');
    expect($data)->not->toHaveKey('encrypted_identity_snapshot')
        ->and($data)->not->toHaveKey('resolved_by_user_id');
});
