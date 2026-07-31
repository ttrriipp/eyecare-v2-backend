<?php

use App\Actions\Auth\IssueOtpChallenge;
use App\Enums\OtpPurpose;
use App\Models\OtpChallenge;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

// --- OTP Issuance ---

test('issuing an OTP creates a challenge record', function () {
    $challenge = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );

    expect($challenge)->toBeInstanceOf(OtpChallenge::class)
        ->and($challenge->public_id)->toBeUuid()
        ->and($challenge->purpose)->toBe(OtpPurpose::Registration)
        ->and($challenge->channel)->toBe('email')
        ->and($challenge->expires_at->isFuture())->toBeTrue();
});

test('OTP code digest is stored, not the plain code', function () {
    $challenge = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );

    $raw = DB::table('otp_challenges')->where('id', $challenge->id)->first();

    expect($raw->code_digest)->not->toBeNull()
        ->and(strlen($raw->code_digest))->toBeGreaterThan(10); // bcrypt hash
});

test('destination is encrypted at rest', function () {
    $challenge = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );

    $raw = DB::table('otp_challenges')->where('id', $challenge->id)->first();

    expect($raw->encrypted_destination)->not->toBe('test@example.com');
});

test('destination hash is a blind index', function () {
    $challenge = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );

    expect($challenge->destination_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($challenge->destination_hash)->not->toContain('test@example.com');
});

// --- Invalidation of Earlier Challenges ---

test('issuing a new OTP invalidates earlier pending challenges for the same destination', function () {
    $first = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );

    $second = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );

    expect($first->fresh()->invalidated_at)->not->toBeNull()
        ->and($second->fresh()->invalidated_at)->toBeNull();
});

test('invalidation only affects the same purpose and destination', function () {
    $registration = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );

    $login = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::LoginStepUp,
    );

    expect($registration->fresh()->invalidated_at)->toBeNull()
        ->and($login->fresh()->invalidated_at)->toBeNull();
});

// --- Rate Limiting (API level) ---

test('registration OTP endpoint returns a challenge ID', function () {
    $response = $this->postJson('/api/v1/auth/registration/otp', [
        'contact_type' => 'email',
        'contact_value' => 'test@example.com',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['challenge_id', 'expires_at']]);
});

test('registration OTP endpoint validates required fields', function () {
    $response = $this->postJson('/api/v1/auth/registration/otp', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_type', 'contact_value']);
});

test('registration OTP endpoint rejects invalid contact type', function () {
    $response = $this->postJson('/api/v1/auth/registration/otp', [
        'contact_type' => 'invalid',
        'contact_value' => 'test@example.com',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_type']);
});

// --- User Association ---

test('OTP can be issued with a user association', function () {
    $user = User::factory()->patient()->create();

    $challenge = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::LoginStepUp,
        userId: $user->id,
    );

    expect($challenge->user_id)->toBe($user->id);
});

test('OTP can be issued without a user association', function () {
    $challenge = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );

    expect($challenge->user_id)->toBeNull();
});
