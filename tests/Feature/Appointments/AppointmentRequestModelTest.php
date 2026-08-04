<?php

use App\Models\AppointmentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('encrypted identity snapshot is cast correctly', function () {
    $snapshot = [
        'first_name' => 'Ana',
        'middle_name' => 'Santos',
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

    $fresh = $request->fresh();

    expect($fresh->encrypted_identity_snapshot)->toBeArray()
        ->and($fresh->encrypted_identity_snapshot['first_name'])->toBe('Ana')
        ->and($fresh->encrypted_identity_snapshot['last_name'])->toBe('Reyes')
        ->and($fresh->encrypted_identity_snapshot['date_of_birth'])->toBe('1990-05-15');
});

test('snapshot is encrypted at rest', function () {
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

    // Read raw database value
    $raw = DB::table('appointment_requests')
        ->where('id', $request->id)
        ->value('encrypted_identity_snapshot');

    // Raw value should not contain plaintext PII
    expect($raw)->not->toContain('Ana')
        ->and($raw)->not->toContain('Reyes')
        ->and($raw)->not->toContain('1990-05-15')
        ->and($raw)->not->toContain('091***4567');
});

test('has identity snapshot returns true for snapshotted request', function () {
    $request = AppointmentRequest::factory()->withSnapshot()->create();

    expect($request->hasIdentitySnapshot())->toBeTrue();
});

test('has identity snapshot returns false for linked request', function () {
    $request = AppointmentRequest::factory()->create([
        'encrypted_identity_snapshot' => null,
    ]);

    expect($request->hasIdentitySnapshot())->toBeFalse();
});

test('snapshot display name is deterministic', function () {
    $request = AppointmentRequest::factory()->withSnapshot()->create();

    $name = $request->getSnapshotDisplayName();

    expect($name)->toBeString()
        ->and($name)->toContain(' ');
});

test('snapshot phone is returned unmasked', function () {
    $request = AppointmentRequest::factory()->withSnapshot()->create();

    expect($request->getSnapshotPhone())->toBe('+639171234567');
});

test('snapshot date of birth is returned', function () {
    $request = AppointmentRequest::factory()->withSnapshot()->create();

    expect($request->getSnapshotDateOfBirth())->toBeString()
        ->and($request->getSnapshotDateOfBirth())->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

test('snapshot helpers return null for linked request', function () {
    $request = AppointmentRequest::factory()->create([
        'encrypted_identity_snapshot' => null,
    ]);

    expect($request->getSnapshotDisplayName())->toBeNull()
        ->and($request->getSnapshotPhone())->toBeNull()
        ->and($request->getSnapshotDateOfBirth())->toBeNull();
});

test('factory withSnapshot creates valid snapshot', function () {
    $request = AppointmentRequest::factory()->withSnapshot()->create();

    expect($request->hasIdentitySnapshot())->toBeTrue()
        ->and($request->getSnapshotDisplayName())->toBeString()
        ->and($request->getSnapshotPhone())->toBeString()
        ->and($request->getSnapshotDateOfBirth())->toBeString();
});
