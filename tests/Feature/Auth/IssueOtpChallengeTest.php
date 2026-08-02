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
    $result = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );

    $challenge = $result['challenge'];

    expect($challenge)->toBeInstanceOf(OtpChallenge::class)
        ->and($challenge->public_id)->toBeUuid()
        ->and($challenge->purpose)->toBe(OtpPurpose::Registration)
        ->and($challenge->channel)->toBe('email')
        ->and($challenge->expires_at->isFuture())->toBeTrue()
        ->and($result['code'])->toBeString();
});

test('OTP code digest is stored, not the plain code', function () {
    $result = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );

    $challenge = $result['challenge'];
    $raw = DB::table('otp_challenges')->where('id', $challenge->id)->first();

    expect($raw->code_digest)->not->toBeNull()
        ->and(strlen($raw->code_digest))->toBeGreaterThan(10);
});

test('destination is encrypted at rest', function () {
    $result = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );

    $challenge = $result['challenge'];
    $raw = DB::table('otp_challenges')->where('id', $challenge->id)->first();

    expect($raw->encrypted_destination)->not->toBe('test@example.com');
});

test('destination hash is a blind index', function () {
    $result = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );

    $challenge = $result['challenge'];

    expect($challenge->destination_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($challenge->destination_hash)->not->toContain('test@example.com');
});

// --- Invalidation ---

test('issuing a new OTP invalidates earlier pending challenges', function () {
    $first = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    )['challenge'];

    app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );

    expect($first->fresh()->invalidated_at)->not->toBeNull();
});

test('invalidation only affects the same purpose', function () {
    $registration = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    )['challenge'];

    app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::LoginStepUp,
    );

    expect($registration->fresh()->invalidated_at)->toBeNull();
});

// --- API ---

test('registration OTP endpoint returns a challenge ID', function () {
    $response = $this->postJson('/api/v1/auth/registration/otp', [
        'contact_type' => 'phone',
        'contact_value' => '09171234567',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['challenge_id', 'expires_at']]);
});

test('registration OTP endpoint only accepts phone contacts', function () {
    $response = $this->postJson('/api/v1/auth/registration/otp', [
        'contact_type' => 'email',
        'contact_value' => 'test@example.com',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_type']);
});

test('registration OTP endpoint rejects an already-owned phone', function () {
    User::factory()->patient()->create([
        'phone' => '+639171234567',
    ]);

    $response = $this->postJson('/api/v1/auth/registration/otp', [
        'contact_type' => 'phone',
        'contact_value' => '09171234567',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('error.code', 'CONTACT_ALREADY_OWNED')
        ->assertJsonPath('error.message', 'This phone number is already registered.');

    expect(OtpChallenge::query()->where('purpose', OtpPurpose::Registration)->count())->toBe(0);
});

test('registration OTP endpoint validates required fields', function () {
    $response = $this->postJson('/api/v1/auth/registration/otp', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_type', 'contact_value']);
});

// --- User Association ---

test('OTP can be issued with a user association', function () {
    $user = User::factory()->patient()->create();

    $result = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::LoginStepUp,
        userId: $user->id,
    );

    expect($result['challenge']->user_id)->toBe($user->id);
});

test('OTP can be issued without a user association', function () {
    $result = app(IssueOtpChallenge::class)->handle(
        contactType: 'email',
        contactValue: 'test@example.com',
        purpose: OtpPurpose::Registration,
    );

    expect($result['challenge']->user_id)->toBeNull();
});
