<?php

use App\Enums\OtpPurpose;
use App\Models\OtpChallenge;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    RateLimiter::clear('otp_verify:*');
    RateLimiter::clear('otp_verify_ip:*');
});

// --- Two-Stage Registration ---

test('registration creates a patient-role user with verified contact', function () {
    // Stage 1: Verify OTP and get registration token
    $code = '123456';
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::Registration,
        'channel' => 'email',
        'encrypted_destination' => 'newuser@example.com',
        'destination_hash' => hash('sha256', 'newuser@example.com'),
    ]);

    $verifyResponse = $this->postJson('/api/v1/auth/registration/verify', [
        'challenge_id' => $challenge->public_id,
        'code' => $code,
    ]);

    $verifyResponse->assertOk();
    $registrationToken = $verifyResponse->json('data.registration_token');

    // Stage 2: Complete registration
    $response = $this->postJson('/api/v1/auth/register', [
        'registration_token' => $registrationToken,
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '09171234567',
        'password' => 'securepassword123',
        'password_confirmation' => 'securepassword123',
        'privacy_policy_version' => config('app.privacy_policy_version'),
        'terms_version' => config('app.terms_version'),
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['token', 'user']]);

    $this->assertDatabaseHas('users', [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
    ]);

    $this->assertDatabaseMissing('patients', [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
    ]);

    $user = User::where('first_name', 'Ana')->first();
    $this->assertDatabaseHas('patient_account_contacts', [
        'user_id' => $user->id,
        'type' => 'email',
        'is_primary' => true,
    ]);
});

test('registration does not create a patient record', function () {
    $code = '123456';
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::Registration,
        'channel' => 'email',
        'encrypted_destination' => 'newuser@example.com',
        'destination_hash' => hash('sha256', 'newuser@example.com'),
    ]);

    $verifyResponse = $this->postJson('/api/v1/auth/registration/verify', [
        'challenge_id' => $challenge->public_id,
        'code' => $code,
    ]);

    $registrationToken = $verifyResponse->json('data.registration_token');

    $this->postJson('/api/v1/auth/register', [
        'registration_token' => $registrationToken,
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '09171234567',
        'password' => 'securepassword123',
        'password_confirmation' => 'securepassword123',
        'privacy_policy_version' => config('app.privacy_policy_version'),
        'terms_version' => config('app.terms_version'),
    ]);

    $this->assertDatabaseCount('patients', 0);
});

test('registration returns a Sanctum token', function () {
    $code = '123456';
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::Registration,
        'channel' => 'email',
        'encrypted_destination' => 'newuser@example.com',
        'destination_hash' => hash('sha256', 'newuser@example.com'),
    ]);

    $verifyResponse = $this->postJson('/api/v1/auth/registration/verify', [
        'challenge_id' => $challenge->public_id,
        'code' => $code,
    ]);

    $registrationToken = $verifyResponse->json('data.registration_token');

    $response = $this->postJson('/api/v1/auth/register', [
        'registration_token' => $registrationToken,
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '09171234567',
        'password' => 'securepassword123',
        'password_confirmation' => 'securepassword123',
        'privacy_policy_version' => config('app.privacy_policy_version'),
        'terms_version' => config('app.terms_version'),
    ]);

    $token = $response->json('data.token');
    expect($token)->not->toBeNull()
        ->and($token)->toContain('|');
});

// --- Validation ---

test('registration requires all fields', function () {
    $response = $this->postJson('/api/v1/auth/register', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'registration_token', 'first_name', 'last_name',
            'date_of_birth', 'password', 'privacy_policy_version', 'terms_version',
        ]);
});

test('registration rejects invalid OTP code', function () {
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make('123456'),
        'purpose' => OtpPurpose::Registration,
    ]);

    $response = $this->postJson('/api/v1/auth/registration/verify', [
        'challenge_id' => $challenge->public_id,
        'code' => '999999',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});
