<?php

use App\Enums\AppointmentRequestStatus;
use App\Models\AppointmentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a pending request reports expired as its effective status after its deadline', function (): void {
    $request = AppointmentRequest::factory()->linked()->create([
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => now()->subMinute(),
    ]);

    expect($request->status)->toBe(AppointmentRequestStatus::Pending)
        ->and($request->isPending())->toBeFalse()
        ->and($request->effectiveStatus())->toBe(AppointmentRequestStatus::Expired);
});

test('a pending request keeps its effective status before its deadline', function (): void {
    $request = AppointmentRequest::factory()->linked()->create([
        'status' => AppointmentRequestStatus::Pending,
        'expires_at' => now()->addMinute(),
    ]);

    expect($request->effectiveStatus())->toBe(AppointmentRequestStatus::Pending);
});

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

    $raw = DB::table('appointment_requests')
        ->where('id', $request->id)
        ->value('encrypted_identity_snapshot');

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

test('alternative scheduled times cast to array', function () {
    $times = [
        '2026-08-20T10:30:00+08:00',
        '2026-08-21T09:00:00+08:00',
    ];

    $request = AppointmentRequest::factory()->create([
        'alternative_scheduled_times' => $times,
    ]);

    expect($request->fresh()->alternative_scheduled_times)->toBeArray()
        ->and($request->fresh()->alternative_scheduled_times)->toHaveCount(2);
});

test('alternative scheduled times are null by default', function () {
    $request = AppointmentRequest::factory()->create();

    expect($request->fresh()->alternative_scheduled_times)->toBeNull();
});

test('encrypted referring source is cast correctly', function () {
    $request = AppointmentRequest::factory()->create([
        'encrypted_referring_source' => 'Dr. Garcia - City Hospital',
    ]);

    expect($request->fresh()->encrypted_referring_source)->toBe('Dr. Garcia - City Hospital');
});

test('referring source is encrypted at rest', function () {
    $request = AppointmentRequest::factory()->create([
        'encrypted_referring_source' => 'Dr. Garcia - City Hospital',
    ]);

    $raw = DB::table('appointment_requests')
        ->where('id', $request->id)
        ->value('encrypted_referring_source');

    expect($raw)->not->toContain('Dr. Garcia');
});

test('get all time preferences returns primary and alternatives', function () {
    $primary = now()->addDays(3)->setTime(9, 15);

    $request = AppointmentRequest::factory()->create([
        'scheduled_at' => $primary,
        'alternative_scheduled_times' => [
            now()->addDays(3)->setTime(10, 30)->toISOString(),
            now()->addDays(4)->setTime(9, 0)->toISOString(),
        ],
    ]);

    $preferences = $request->getAllTimePreferences();

    expect($preferences)->toHaveCount(3);
});

test('get all time preferences returns only primary when no alternatives', function () {
    $request = AppointmentRequest::factory()->create([
        'alternative_scheduled_times' => null,
    ]);

    $preferences = $request->getAllTimePreferences();

    expect($preferences)->toHaveCount(1);
});

test('legacy request with null new fields is readable', function () {
    $request = AppointmentRequest::factory()->legacy()->create();

    expect($request->alternative_scheduled_times)->toBeNull()
        ->and($request->encrypted_referring_source)->toBeNull()
        ->and($request->appointment_type_id)->toBeNull();
});
