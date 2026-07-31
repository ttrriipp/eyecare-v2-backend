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

// --- Password Login ---

test('patient login with valid credentials returns step-up challenge', function () {
    $user = User::factory()->patient()->create(['password' => bcrypt('securepassword123')]);
    PatientAccountContact::factory()->email('test@example.com')->verified()->primary()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'contact_value' => 'test@example.com',
        'password' => 'securepassword123',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.step_up_required', true)
        ->assertJsonStructure(['data' => ['challenge_id', 'expires_at']]);
});

test('patient login with wrong password fails', function () {
    $user = User::factory()->patient()->create(['password' => bcrypt('securepassword123')]);
    PatientAccountContact::factory()->email('test@example.com')->verified()->primary()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'contact_value' => 'test@example.com',
        'password' => 'wrongpassword',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_value']);
});

test('patient login with unknown contact fails', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'contact_value' => 'unknown@example.com',
        'password' => 'securepassword123',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_value']);
});

// --- Login Verification ---

test('login verification issues a Sanctum token', function () {
    $user = User::factory()->patient()->create(['password' => bcrypt('securepassword123')]);
    PatientAccountContact::factory()->email('test@example.com')->verified()->primary()->create([
        'user_id' => $user->id,
    ]);

    $code = '123456';
    $challenge = OtpChallenge::factory()->pending()->create([
        'user_id' => $user->id,
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::LoginStepUp,
    ]);

    $response = $this->postJson('/api/v1/auth/login/verify', [
        'challenge_id' => $challenge->public_id,
        'code' => $code,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user']]);

    $token = $response->json('data.token');
    expect($token)->not->toBeNull()
        ->and($token)->toContain('|');
});

test('login verification with wrong code fails', function () {
    $user = User::factory()->patient()->create();
    $challenge = OtpChallenge::factory()->pending()->create([
        'user_id' => $user->id,
        'code_digest' => Hash::make('123456'),
        'purpose' => OtpPurpose::LoginStepUp,
    ]);

    $response = $this->postJson('/api/v1/auth/login/verify', [
        'challenge_id' => $challenge->public_id,
        'code' => '999999',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

// --- Enumeration Safety ---

test('login responses are identical for wrong password and unknown contact', function () {
    $user = User::factory()->patient()->create(['password' => bcrypt('securepassword123')]);
    PatientAccountContact::factory()->email('test@example.com')->verified()->primary()->create([
        'user_id' => $user->id,
    ]);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'contact_value' => 'test@example.com',
        'password' => 'wrongpassword',
    ]);

    $unknownContact = $this->postJson('/api/v1/auth/login', [
        'contact_value' => 'unknown@example.com',
        'password' => 'securepassword123',
    ]);

    // Both should return the same status and error structure
    expect($wrongPassword->getStatusCode())->toBe($unknownContact->getStatusCode())
        ->and($wrongPassword->json('errors.contact_value'))->toBe($unknownContact->json('errors.contact_value'));
});
