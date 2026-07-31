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

// --- Successful Registration ---

test('registration creates a patient-role user with verified contact', function () {
    $code = '123456';
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::Registration,
        'channel' => 'email',
        'encrypted_destination' => 'newuser@example.com',
        'destination_hash' => hash('sha256', 'newuser@example.com'),
    ]);

    $response = $this->postJson('/api/v1/auth/register', [
        'challenge_id' => $challenge->public_id,
        'code' => $code,
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '09171234567',
        'password' => 'securepassword123',
        'password_confirmation' => 'securepassword123',
        'privacy_policy_accepted' => true,
        'terms_accepted' => true,
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['token', 'user']]);

    // Verify user was created
    $this->assertDatabaseHas('users', [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
    ]);

    // Verify no Patient was created
    $this->assertDatabaseMissing('patients', [
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
    ]);

    // Verify contact was created
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

    $this->postJson('/api/v1/auth/register', [
        'challenge_id' => $challenge->public_id,
        'code' => $code,
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '09171234567',
        'password' => 'securepassword123',
        'password_confirmation' => 'securepassword123',
        'privacy_policy_accepted' => true,
        'terms_accepted' => true,
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

    $response = $this->postJson('/api/v1/auth/register', [
        'challenge_id' => $challenge->public_id,
        'code' => $code,
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '09171234567',
        'password' => 'securepassword123',
        'password_confirmation' => 'securepassword123',
        'privacy_policy_accepted' => true,
        'terms_accepted' => true,
    ]);

    $token = $response->json('data.token');
    expect($token)->not->toBeNull()
        ->and($token)->toContain('|');
});

// --- No Duplicate for Owned Contact ---

test('registration with already-owned contact returns existing account', function () {
    $existingUser = User::factory()->patient()->create();
    $code = '123456';
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::Registration,
        'channel' => 'email',
        'encrypted_destination' => 'existing@example.com',
        'destination_hash' => hash('sha256', 'existing@example.com'),
    ]);

    PatientAccountContact::create([
        'user_id' => $existingUser->id,
        'type' => 'email',
        'encrypted_value' => 'existing@example.com',
        'lookup_hash' => hash('sha256', 'existing@example.com'),
        'verified_at' => now(),
        'is_primary' => true,
    ]);

    $response = $this->postJson('/api/v1/auth/register', [
        'challenge_id' => $challenge->public_id,
        'code' => $code,
        'first_name' => 'New',
        'last_name' => 'User',
        'date_of_birth' => '1995-01-01',
        'phone' => '09179876543',
        'password' => 'securepassword123',
        'password_confirmation' => 'securepassword123',
        'privacy_policy_accepted' => true,
        'terms_accepted' => true,
    ]);

    $response->assertCreated();

    // Should not create a second user with these names
    $this->assertDatabaseMissing('users', ['first_name' => 'New', 'last_name' => 'User']);
});

// --- Validation ---

test('registration requires all fields', function () {
    $response = $this->postJson('/api/v1/auth/register', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'challenge_id', 'code', 'first_name', 'last_name',
            'date_of_birth', 'phone', 'password', 'privacy_policy_accepted', 'terms_accepted',
        ]);
});

test('registration requires 12-character password', function () {
    $code = '123456';
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::Registration,
    ]);

    $response = $this->postJson('/api/v1/auth/register', [
        'challenge_id' => $challenge->public_id,
        'code' => $code,
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '09171234567',
        'password' => 'short',
        'password_confirmation' => 'short',
        'privacy_policy_accepted' => true,
        'terms_accepted' => true,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

test('registration requires password confirmation', function () {
    $code = '123456';
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::Registration,
    ]);

    $response = $this->postJson('/api/v1/auth/register', [
        'challenge_id' => $challenge->public_id,
        'code' => $code,
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '09171234567',
        'password' => 'securepassword123',
        'password_confirmation' => 'differentpassword',
        'privacy_policy_accepted' => true,
        'terms_accepted' => true,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

test('registration rejects invalid OTP code', function () {
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make('123456'),
        'purpose' => OtpPurpose::Registration,
    ]);

    $response = $this->postJson('/api/v1/auth/register', [
        'challenge_id' => $challenge->public_id,
        'code' => '999999',
        'first_name' => 'Ana',
        'last_name' => 'Reyes',
        'date_of_birth' => '1990-05-15',
        'phone' => '09171234567',
        'password' => 'securepassword123',
        'password_confirmation' => 'securepassword123',
        'privacy_policy_accepted' => true,
        'terms_accepted' => true,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});
