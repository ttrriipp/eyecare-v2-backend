<?php

use App\Actions\Auth\VerifyOtpChallenge;
use App\Enums\OtpPurpose;
use App\Models\OtpChallenge;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    RateLimiter::clear('otp_verify:*');
    RateLimiter::clear('otp_verify_ip:*');
});

// --- Successful Verification ---

test('valid OTP code verifies successfully', function () {
    // Create a challenge with a known code
    $code = '123456';
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::Registration,
    ]);

    $result = app(VerifyOtpChallenge::class)->handle(
        challengeId: $challenge->public_id,
        code: $code,
        expectedPurpose: OtpPurpose::Registration,
    );

    expect($result->isConsumed())->toBeTrue()
        ->and($result->consumed_at)->not->toBeNull();
});

// --- Failure Cases ---

test('invalid challenge ID fails', function () {
    app(VerifyOtpChallenge::class)->handle(
        challengeId: 'non-existent',
        code: '123456',
        expectedPurpose: OtpPurpose::Registration,
    );
})->throws(ValidationException::class, 'The provided challenge is invalid.');

test('wrong purpose fails', function () {
    $challenge = OtpChallenge::factory()->pending()->purpose(OtpPurpose::LoginStepUp)->create();

    app(VerifyOtpChallenge::class)->handle(
        challengeId: $challenge->public_id,
        code: '123456',
        expectedPurpose: OtpPurpose::Registration,
    );
})->throws(ValidationException::class, 'The provided challenge is invalid.');

test('expired challenge fails', function () {
    $challenge = OtpChallenge::factory()->expired()->create();

    app(VerifyOtpChallenge::class)->handle(
        challengeId: $challenge->public_id,
        code: '123456',
        expectedPurpose: OtpPurpose::Registration,
    );
})->throws(ValidationException::class, 'The verification code has expired.');

test('consumed challenge fails', function () {
    $challenge = OtpChallenge::factory()->consumed()->create();

    app(VerifyOtpChallenge::class)->handle(
        challengeId: $challenge->public_id,
        code: '123456',
        expectedPurpose: OtpPurpose::Registration,
    );
})->throws(ValidationException::class, 'This verification code has already been used.');

test('invalidated challenge fails', function () {
    $challenge = OtpChallenge::factory()->invalidated()->create();

    app(VerifyOtpChallenge::class)->handle(
        challengeId: $challenge->public_id,
        code: '123456',
        expectedPurpose: OtpPurpose::Registration,
    );
})->throws(ValidationException::class, 'This verification code is no longer valid.');

test('wrong code increments attempts', function () {
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make('123456'),
        'attempts' => 0,
    ]);

    try {
        app(VerifyOtpChallenge::class)->handle(
            challengeId: $challenge->public_id,
            code: '999999',
            expectedPurpose: OtpPurpose::Registration,
        );
    } catch (ValidationException) {
    }

    expect($challenge->fresh()->attempts)->toBe(1);
});

test('exhausted attempts invalidate the challenge', function () {
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make('123456'),
        'attempts' => 4,
        'max_attempts' => 5,
    ]);

    try {
        app(VerifyOtpChallenge::class)->handle(
            challengeId: $challenge->public_id,
            code: '999999',
            expectedPurpose: OtpPurpose::Registration,
        );
    } catch (ValidationException) {
    }

    expect($challenge->fresh()->attempts)->toBe(5)
        ->and($challenge->fresh()->invalidated_at)->not->toBeNull();
});

// --- API Level ---

test('registration verify endpoint returns a registration proof on success', function () {
    $code = '123456';
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::Registration,
        'channel' => 'phone',
    ]);

    $response = $this->postJson('/api/v1/auth/registration/verify', [
        'challenge_id' => $challenge->public_id,
        'code' => $code,
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => ['registration_token', 'expires_at', 'contact_type'],
        ])
        ->assertJsonPath('data.contact_type', $challenge->channel);

    expect($response->json('data.registration_token'))->not->toBeEmpty();
});

test('verify endpoint validates required fields', function () {
    $response = $this->postJson('/api/v1/auth/registration/verify', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['challenge_id', 'code']);
});

test('verify endpoint rejects wrong code', function () {
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make('123456'),
        'purpose' => OtpPurpose::Registration,
        'channel' => 'phone',
    ]);

    $response = $this->postJson('/api/v1/auth/registration/verify', [
        'challenge_id' => $challenge->public_id,
        'code' => '999999',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});
