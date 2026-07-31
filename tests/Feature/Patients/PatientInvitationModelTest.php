<?php

use App\Enums\PatientInvitationStatus;
use App\Models\PatientInvitation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('invitation has a public_id', function () {
    $invitation = PatientInvitation::factory()->create();

    expect($invitation->public_id)->toBeUuid();
});

test('invitation belongs to a patient and sender', function () {
    $invitation = PatientInvitation::factory()->create();

    expect($invitation->patient)->not->toBeNull()
        ->and($invitation->sender)->not->toBeNull();
});

test('invitation defaults to pending status', function () {
    $invitation = PatientInvitation::factory()->create();

    expect($invitation->status)->toBe(PatientInvitationStatus::Pending);
});

test('destination is encrypted at rest', function () {
    $invitation = PatientInvitation::factory()->create([
        'encrypted_destination' => 'test@example.com',
    ]);

    $raw = DB::table('patient_invitations')->where('id', $invitation->id)->first();

    expect($raw->encrypted_destination)->not->toBe('test@example.com');
});

test('invitation can be revoked', function () {
    $invitation = PatientInvitation::factory()->create();

    $invitation->revoke();

    expect($invitation->fresh()->status)->toBe(PatientInvitationStatus::Revoked)
        ->and($invitation->fresh()->revoked_at)->not->toBeNull();
});

test('invitation can be accepted by a user', function () {
    $invitation = PatientInvitation::factory()->create();
    $user = User::factory()->patient()->create();

    $invitation->accept($user);

    expect($invitation->fresh()->status)->toBe(PatientInvitationStatus::Accepted)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($invitation->fresh()->accepted_by_user_id)->toBe($user->id);
});

test('expired invitation is detected', function () {
    $invitation = PatientInvitation::factory()->expired()->create();

    expect($invitation->isExpired())->toBeTrue()
        ->and($invitation->isPending())->toBeFalse();
});

test('factory states produce valid records', function () {
    $pending = PatientInvitation::factory()->create();
    $accepted = PatientInvitation::factory()->accepted()->create();
    $expired = PatientInvitation::factory()->expired()->create();
    $revoked = PatientInvitation::factory()->revoked()->create();

    expect($pending->status)->toBe(PatientInvitationStatus::Pending)
        ->and($accepted->status)->toBe(PatientInvitationStatus::Accepted)
        ->and($expired->status)->toBe(PatientInvitationStatus::Expired)
        ->and($revoked->status)->toBe(PatientInvitationStatus::Revoked);
});
