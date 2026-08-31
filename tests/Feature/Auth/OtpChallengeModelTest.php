<?php

use App\Enums\OtpPurpose;
use App\Models\OtpChallenge;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

// --- Model Basics ---

test('otp challenge has a public_id', function () {
    $challenge = OtpChallenge::factory()->create();

    expect($challenge->public_id)->toBeUuid();
});

test('otp challenge belongs to a user optionally', function () {
    $withUser = OtpChallenge::factory()->forUser(User::factory()->patient()->create())->create();
    $withoutUser = OtpChallenge::factory()->create(['user_id' => null]);

    expect($withUser->user)->not->toBeNull()
        ->and($withoutUser->user)->toBeNull();
});

// --- Purpose Enum ---

test('otp purpose covers all required values', function () {
    expect(OtpPurpose::cases())->toHaveCount(8);

    $values = array_map(fn ($c) => $c->value, OtpPurpose::cases());
    expect($values)->toContain(
        'registration',
        'login_step_up',
        'password_recovery',
        'add_contact',
        'replace_primary_contact',
        'invitation_acceptance',
        'sensitive_change',
        'step_up',
    );
});

// --- Encrypted Destination ---

test('destination is encrypted at rest', function () {
    $challenge = OtpChallenge::factory()->create([
        'encrypted_destination' => 'test@example.com',
    ]);

    $raw = DB::table('otp_challenges')->where('id', $challenge->id)->first();

    expect($raw->encrypted_destination)->not->toBe('test@example.com')
        ->and($raw->encrypted_destination)->not->toBeNull();
});

// --- State Checks ---

test('pending challenge is not expired, consumed, or invalidated', function () {
    $challenge = OtpChallenge::factory()->pending()->create();

    expect($challenge->isPending())->toBeTrue()
        ->and($challenge->isExpired())->toBeFalse()
        ->and($challenge->isConsumed())->toBeFalse()
        ->and($challenge->isInvalidated())->toBeFalse();
});

test('expired challenge is not pending', function () {
    $challenge = OtpChallenge::factory()->expired()->create();

    expect($challenge->isPending())->toBeFalse()
        ->and($challenge->isExpired())->toBeTrue();
});

test('consumed challenge is not pending', function () {
    $challenge = OtpChallenge::factory()->consumed()->create();

    expect($challenge->isPending())->toBeFalse()
        ->and($challenge->isConsumed())->toBeTrue();
});

test('invalidated challenge is not pending', function () {
    $challenge = OtpChallenge::factory()->invalidated()->create();

    expect($challenge->isPending())->toBeFalse()
        ->and($challenge->isInvalidated())->toBeTrue();
});

test('exhausted challenge has no remaining attempts', function () {
    $challenge = OtpChallenge::factory()->exhausted()->create();

    expect($challenge->hasAttemptsRemaining())->toBeFalse()
        ->and($challenge->isPending())->toBeFalse();
});

// --- Attempt Tracking ---

test('incrementing attempts tracks the count', function () {
    $challenge = OtpChallenge::factory()->pending()->create(['attempts' => 0]);

    $challenge->incrementAttempts();

    expect($challenge->fresh()->attempts)->toBe(1);
});

test('exhausting attempts invalidates the challenge', function () {
    $challenge = OtpChallenge::factory()->pending()->create([
        'attempts' => 4,
        'max_attempts' => 5,
    ]);

    $challenge->incrementAttempts();

    expect($challenge->fresh()->attempts)->toBe(5)
        ->and($challenge->fresh()->invalidated_at)->not->toBeNull();
});

// --- Consumption ---

test('consuming a challenge records the timestamp', function () {
    $challenge = OtpChallenge::factory()->pending()->create();

    $challenge->consume();

    expect($challenge->fresh()->consumed_at)->not->toBeNull()
        ->and($challenge->fresh()->isConsumed())->toBeTrue();
});

// --- Invalidation ---

test('invalidating a challenge records the timestamp', function () {
    $challenge = OtpChallenge::factory()->pending()->create();

    $challenge->invalidate();

    expect($challenge->fresh()->invalidated_at)->not->toBeNull()
        ->and($challenge->fresh()->isInvalidated())->toBeTrue();
});

// --- Delivery Status ---

test('challenge tracks delivery status', function () {
    $challenge = OtpChallenge::factory()->create(['delivery_status' => 'pending']);

    $challenge->markSent();
    expect($challenge->fresh()->delivery_status)->toBe('sent')
        ->and($challenge->fresh()->last_sent_at)->not->toBeNull();

    $failed = OtpChallenge::factory()->create(['delivery_status' => 'pending']);
    $failed->markFailed();
    expect($failed->fresh()->delivery_status)->toBe('failed');
});

// --- Code Digest ---

test('plain OTP code is never stored', function () {
    $code = '123456';
    $challenge = OtpChallenge::factory()->create([
        'code_digest' => Hash::make($code),
    ]);

    $raw = DB::table('otp_challenges')->where('id', $challenge->id)->first();

    expect($raw->code_digest)->not->toBe($code)
        ->and(Hash::check($code, $raw->code_digest))->toBeTrue();
});

// --- Factory States ---

test('factory states produce valid records', function () {
    $pending = OtpChallenge::factory()->pending()->create();
    $expired = OtpChallenge::factory()->expired()->create();
    $consumed = OtpChallenge::factory()->consumed()->create();
    $invalidated = OtpChallenge::factory()->invalidated()->create();
    $exhausted = OtpChallenge::factory()->exhausted()->create();

    expect($pending->isPending())->toBeTrue()
        ->and($expired->isExpired())->toBeTrue()
        ->and($consumed->isConsumed())->toBeTrue()
        ->and($invalidated->isInvalidated())->toBeTrue()
        ->and($exhausted->hasAttemptsRemaining())->toBeFalse();
});
