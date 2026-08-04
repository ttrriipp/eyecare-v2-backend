<?php

use App\Enums\AppointmentRequestStatus;
use App\Models\AppointmentRequest;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('submit response for linked account contains no snapshot data', function () {
    $user = User::factory()->patient()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/appointment-requests', [
            'scheduled_at' => now()->addDays(3)->format('Y-m-d\TH:i:sP'),
            'reason_for_visit' => 'Blurred vision',
        ])
        ->assertCreated();

    $jsonString = json_encode($response->json());

    expect($jsonString)->not->toContain('encrypted_identity_snapshot')
        ->and($jsonString)->not->toContain('verified_contact_type')
        ->and($jsonString)->not->toContain('verified_contact_masked')
        ->and($jsonString)->not->toContain('verified_contact_hash')
        ->and($jsonString)->not->toContain('submitted_at');
});

test('linked request response has no snapshot fields', function () {
    $user = User::factory()->patient()->create();

    $request = AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'encrypted_identity_snapshot' => null,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/appointment-requests/{$request->id}")
        ->assertOk();

    $jsonString = json_encode($response->json());

    expect($jsonString)->not->toContain('encrypted_identity_snapshot')
        ->and($jsonString)->not->toContain('verified_contact_type')
        ->and($jsonString)->not->toContain('verified_contact_masked')
        ->and($jsonString)->not->toContain('verified_contact_hash')
        ->and($jsonString)->not->toContain('submitted_at');
});

test('appointment request list response excludes identity snapshot data', function () {
    $user = User::factory()->create();

    AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'encrypted_identity_snapshot' => [
            'phone' => '+639171234567',
            'email' => 'ana@example.com',
            'first_name' => 'Ana',
            'last_name' => 'Reyes',
            'date_of_birth' => '1990-05-15',
            'gender' => 'female',
            'occupation' => 'Teacher',
            'address' => '123 Main St, Manila',
            'verified_contact_type' => 'phone',
            'verified_contact_masked' => '+63***567',
            'verified_contact_hash' => 'secret-hash',
            'submitted_at' => now()->toIso8601String(),
        ],
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/appointment-requests')
        ->assertOk();

    $jsonString = json_encode($response->json());

    expect($jsonString)->not->toContain('encrypted_identity_snapshot')
        ->not->toContain('ana@example.com')
        ->not->toContain('+639171234567')
        ->not->toContain('123 Main St, Manila')
        ->not->toContain('verified_contact_hash')
        ->and($response->json('data.0.reason_for_visit'))->toBeString();
});

test('cancel response has no snapshot fields', function () {
    $user = User::factory()->patient()->create();

    $request = AppointmentRequest::factory()->create([
        'user_id' => $user->id,
        'status' => AppointmentRequestStatus::Pending,
        'encrypted_identity_snapshot' => null,
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/appointment-requests/{$request->id}/cancel")
        ->assertOk();

    $jsonString = json_encode($response->json());

    expect($jsonString)->not->toContain('encrypted_identity_snapshot')
        ->and($jsonString)->not->toContain('verified_contact_type')
        ->and($jsonString)->not->toContain('verified_contact_masked');
});

test('encrypted snapshot is not readable in database', function () {
    $snapshot = [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'verified_contact_type' => 'phone',
        'verified_contact_masked' => '091***4567',
        'verified_contact_hash' => 'abc123',
        'submitted_at' => now()->toIso8601String(),
    ];

    $request = AppointmentRequest::factory()->create([
        'encrypted_identity_snapshot' => $snapshot,
    ]);

    $raw = DB::table('appointment_requests')
        ->where('id', $request->id)
        ->value('encrypted_identity_snapshot');

    expect($raw)->not->toContain('Ana')
        ->and($raw)->not->toContain('Reyes')
        ->and($raw)->not->toContain('1990-05-15')
        ->and($raw)->not->toContain('091***4567');
});

test('snapshot helpers return null for linked request', function () {
    $request = AppointmentRequest::factory()->create([
        'encrypted_identity_snapshot' => null,
    ]);

    expect($request->hasIdentitySnapshot())->toBeFalse()
        ->and($request->getSnapshotDisplayName())->toBeNull()
        ->and($request->getSnapshotDateOfBirth())->toBeNull()
        ->and($request->getSnapshotPhone())->toBeNull()
        ->and($request->getSnapshotEmail())->toBeNull()
        ->and($request->getSnapshotGender())->toBeNull()
        ->and($request->getSnapshotOccupation())->toBeNull()
        ->and($request->getSnapshotAddress())->toBeNull();
});

test('snapshot helpers return data for snapshotted request', function () {
    $request = AppointmentRequest::factory()->withSnapshot()->create();

    expect($request->hasIdentitySnapshot())->toBeTrue()
        ->and($request->getSnapshotDisplayName())->toBeString()
        ->and($request->getSnapshotDateOfBirth())->toBeString()
        ->and($request->getSnapshotPhone())->toBe('+639171234567')
        ->and($request->getSnapshotEmail())->toBeNull()
        ->and($request->getSnapshotGender())->toBeIn(['male', 'female', 'other'])
        ->and($request->getSnapshotOccupation())->toBeString()
        ->and($request->getSnapshotAddress())->toBeString();
});
