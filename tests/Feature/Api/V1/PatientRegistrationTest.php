<?php

use App\Actions\PatientAccounts\CreateContactLookupHash;
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

// --- Two-Stage Registration ---

test('registration creates a patient-role user with verified contact', function () {
    // Stage 1: Verify OTP and get registration token
    $code = '123456';
    $phone = '+639171234567';
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::Registration,
        'channel' => 'phone',
        'encrypted_destination' => $phone,
        'destination_hash' => app(CreateContactLookupHash::class)->forPhone($phone),
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
        'type' => 'phone',
        'is_primary' => true,
    ]);
});

test('phone registration accepts an optional unverified email and binds the device token', function () {
    $phone = '+639171234567';
    $email = 'optional@example.com';
    $code = '123456';
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::Registration,
        'channel' => 'phone',
        'encrypted_destination' => $phone,
        'destination_hash' => app(CreateContactLookupHash::class)->forPhone($phone),
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
        'email' => $email,
        'password' => 'securepassword123',
        'password_confirmation' => 'securepassword123',
        'privacy_policy_version' => config('app.privacy_policy_version'),
        'terms_version' => config('app.terms_version'),
        'device_name' => 'Pixel',
        'installation_id' => 'installation-1',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.email_verification_required', true);

    $user = User::query()->where('phone', $phone)->firstOrFail();
    $pendingEmail = $user->contacts()->where('type', 'email')->firstOrFail();

    expect($user->email)->toBe($email)
        ->and($user->email_verified_at)->toBeNull()
        ->and($pendingEmail->verified_at)->toBeNull()
        ->and($pendingEmail->is_primary)->toBeFalse();

    $this->assertDatabaseHas('personal_access_tokens', [
        'name' => 'Pixel',
        'installation_id' => 'installation-1',
    ]);
});

test('registration does not create a patient record', function () {
    $code = '123456';
    $phone = '+639171234567';
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::Registration,
        'channel' => 'phone',
        'encrypted_destination' => $phone,
        'destination_hash' => app(CreateContactLookupHash::class)->forPhone($phone),
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
        'password' => 'securepassword123',
        'password_confirmation' => 'securepassword123',
        'privacy_policy_version' => config('app.privacy_policy_version'),
        'terms_version' => config('app.terms_version'),
    ]);

    $this->assertDatabaseCount('patients', 0);
});

test('registration returns a Sanctum token', function () {
    $code = '123456';
    $phone = '+639171234567';
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::Registration,
        'channel' => 'phone',
        'encrypted_destination' => $phone,
        'destination_hash' => app(CreateContactLookupHash::class)->forPhone($phone),
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
        'password' => 'securepassword123',
        'password_confirmation' => 'securepassword123',
        'privacy_policy_version' => config('app.privacy_policy_version'),
        'terms_version' => config('app.terms_version'),
    ]);

    $token = $response->json('data.token');
    expect($token)->not->toBeNull()
        ->and($token)->toContain('|');
});

test('registration rejects an already-owned email without issuing a token or consuming the proof', function () {
    $email = 'owned@example.com';
    $phone = '+639171234567';
    $existingUser = User::factory()->patient()->create();
    $existingContact = PatientAccountContact::factory()
        ->email($email)
        ->verified()
        ->primary()
        ->create(['user_id' => $existingUser->id]);

    $code = '123456';
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::Registration,
        'channel' => 'phone',
        'encrypted_destination' => $phone,
        'destination_hash' => app(CreateContactLookupHash::class)->forPhone($phone),
    ]);

    $verifyResponse = $this->postJson('/api/v1/auth/registration/verify', [
        'challenge_id' => $challenge->public_id,
        'code' => $code,
    ]);

    $verifyResponse->assertOk();
    $registrationToken = $verifyResponse->json('data.registration_token');
    $proof = OtpChallenge::where('public_id', $registrationToken)->firstOrFail();
    $userCount = User::count();

    $response = $this->postJson('/api/v1/auth/register', [
        'registration_token' => $registrationToken,
        'first_name' => 'New',
        'last_name' => 'Account',
        'date_of_birth' => '1990-05-15',
        'email' => $email,
        'password' => 'securepassword123',
        'password_confirmation' => 'securepassword123',
        'privacy_policy_version' => config('app.privacy_policy_version'),
        'terms_version' => config('app.terms_version'),
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('error.code', 'CONTACT_ALREADY_OWNED')
        ->assertJsonPath('error.message', 'This email address is already registered.')
        ->assertJsonMissingPath('data.token');

    expect(User::count())->toBe($userCount)
        ->and($existingUser->fresh()->tokens()->count())->toBe(0)
        ->and($proof->fresh()->consumed_at)->toBeNull();
});

test('registration rejects an already-owned phone without issuing a token or consuming the proof', function () {
    $phone = '+639171234567';
    $existingUser = User::factory()->patient()->create();
    $existingContact = PatientAccountContact::factory()
        ->phone($phone)
        ->verified()
        ->primary()
        ->create(['user_id' => $existingUser->id]);

    $code = '123456';
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::Registration,
        'channel' => 'phone',
        'encrypted_destination' => $phone,
        'destination_hash' => $existingContact->lookup_hash,
    ]);

    $verifyResponse = $this->postJson('/api/v1/auth/registration/verify', [
        'challenge_id' => $challenge->public_id,
        'code' => $code,
    ]);

    $verifyResponse->assertOk();
    $registrationToken = $verifyResponse->json('data.registration_token');
    $proof = OtpChallenge::where('public_id', $registrationToken)->firstOrFail();
    $userCount = User::count();

    $response = $this->postJson('/api/v1/auth/register', [
        'registration_token' => $registrationToken,
        'first_name' => 'New',
        'last_name' => 'Account',
        'date_of_birth' => '1990-05-15',
        'password' => 'securepassword123',
        'password_confirmation' => 'securepassword123',
        'privacy_policy_version' => config('app.privacy_policy_version'),
        'terms_version' => config('app.terms_version'),
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('error.code', 'CONTACT_ALREADY_OWNED')
        ->assertJsonPath('error.message', 'This phone number is already registered.')
        ->assertJsonMissingPath('data.token');

    expect(User::count())->toBe($userCount)
        ->and($existingUser->fresh()->tokens()->count())->toBe(0)
        ->and($proof->fresh()->consumed_at)->toBeNull();
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
        'channel' => 'phone',
    ]);

    $response = $this->postJson('/api/v1/auth/registration/verify', [
        'challenge_id' => $challenge->public_id,
        'code' => '999999',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});
