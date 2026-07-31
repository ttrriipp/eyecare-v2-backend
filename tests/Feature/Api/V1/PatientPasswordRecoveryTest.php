<?php

use App\Enums\OtpPurpose;
use App\Models\OtpChallenge;
use App\Models\PatientAccountContact;
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

// --- Recovery OTP ---

test('recovery OTP endpoint returns generic response', function () {
    $response = $this->postJson('/api/v1/auth/password-recovery/otp', [
        'contact_value' => 'test@example.com',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.message', 'If the contact is associated with an account, a recovery code has been sent.');
});

test('recovery OTP is enumeration-safe', function () {
    $known = $this->postJson('/api/v1/auth/password-recovery/otp', [
        'contact_value' => 'known@example.com',
    ]);

    $unknown = $this->postJson('/api/v1/auth/password-recovery/otp', [
        'contact_value' => 'unknown@example.com',
    ]);

    expect($known->getStatusCode())->toBe($unknown->getStatusCode())
        ->and($known->json('data.message'))->toBe($unknown->json('data.message'));
});

// --- Recovery Verification ---

test('recovery verification resets password and revokes tokens', function () {
    $user = User::factory()->patient()->create(['password' => bcrypt('oldpassword123')]);
    PatientAccountContact::factory()->email('test@example.com')->verified()->primary()->create([
        'user_id' => $user->id,
    ]);

    // Create some existing tokens
    $user->createToken('device-1');
    $user->createToken('device-2');
    expect($user->tokens()->count())->toBe(2);

    $code = '123456';
    $challenge = OtpChallenge::factory()->pending()->create([
        'user_id' => $user->id,
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::PasswordRecovery,
    ]);

    $response = $this->postJson('/api/v1/auth/password-recovery/verify', [
        'challenge_id' => $challenge->public_id,
        'code' => $code,
        'password' => 'newsecurepassword123',
        'password_confirmation' => 'newsecurepassword123',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user']]);

    // Old tokens should be revoked
    expect($user->fresh()->tokens()->count())->toBe(1); // Only the new token

    // New password should work
    expect(Hash::check('newsecurepassword123', $user->fresh()->password))->toBeTrue();
});

test('recovery verification with wrong code fails', function () {
    $user = User::factory()->patient()->create();
    $challenge = OtpChallenge::factory()->pending()->create([
        'user_id' => $user->id,
        'code_digest' => Hash::make('123456'),
        'purpose' => OtpPurpose::PasswordRecovery,
    ]);

    $response = $this->postJson('/api/v1/auth/password-recovery/verify', [
        'challenge_id' => $challenge->public_id,
        'code' => '999999',
        'password' => 'newsecurepassword123',
        'password_confirmation' => 'newsecurepassword123',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

// --- Logout All ---

test('logout-all revokes all patient tokens', function () {
    $user = User::factory()->patient()->create();
    $user->createToken('device-1');
    $user->createToken('device-2');
    $user->createToken('device-3');

    $this->actingAs($user)
        ->postJson('/api/v1/logout-all')
        ->assertNoContent();

    expect($user->fresh()->tokens()->count())->toBe(0);
});
