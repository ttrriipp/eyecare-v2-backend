<?php

use App\Enums\OtpPurpose;
use App\Models\OtpChallenge;
use App\Models\PatientAccountContact;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

test('step-up OTP accepts the documented sensitive change purpose', function (): void {
    $user = User::factory()->patient()->create();
    PatientAccountContact::factory()->phone('+639171234567')->primary()->create([
        'user_id' => $user->id,
    ]);
    Queue::fake();

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/auth/step-up/otp', [
        'purpose' => OtpPurpose::SensitiveChange->value,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['challenge_id', 'expires_at', 'contact_type', 'masked_contact']]);

    $challenge = OtpChallenge::query()
        ->where('public_id', $response->json('data.challenge_id'))
        ->firstOrFail();

    expect($challenge->purpose)->toBe(OtpPurpose::SensitiveChange);
});

test('step-up OTP defaults to sensitive change when purpose is omitted', function (): void {
    $user = User::factory()->patient()->create();
    PatientAccountContact::factory()->phone('+639171234567')->primary()->create([
        'user_id' => $user->id,
    ]);
    Queue::fake();

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/auth/step-up/otp');

    $response->assertOk();

    $challenge = OtpChallenge::query()
        ->where('public_id', $response->json('data.challenge_id'))
        ->firstOrFail();

    expect($challenge->purpose)->toBe(OtpPurpose::SensitiveChange);
});

test('step-up OTP rejects action-specific purposes', function (): void {
    $user = User::factory()->patient()->create();
    PatientAccountContact::factory()->phone('+639171234567')->primary()->create([
        'user_id' => $user->id,
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/step-up/otp', [
        'purpose' => 'change_password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['purpose']);

    expect(OtpChallenge::query()->count())->toBe(0);
});

test('step-up OTP verification issues a proof that can be used once', function (): void {
    $user = User::factory()->patient()->create();
    PatientAccountContact::factory()->phone('+639171234567')->primary()->create([
        'user_id' => $user->id,
    ]);
    Queue::fake();

    Sanctum::actingAs($user);

    $challengeId = $this->postJson('/api/v1/auth/step-up/otp', [
        'purpose' => OtpPurpose::SensitiveChange->value,
    ])
        ->assertOk()
        ->json('data.challenge_id');

    $challenge = OtpChallenge::query()
        ->where('public_id', $challengeId)
        ->firstOrFail();
    $challenge->update(['code_digest' => Hash::make('123456')]);

    $stepUpToken = $this->postJson('/api/v1/auth/step-up/verify', [
        'challenge_id' => $challengeId,
        'code' => '123456',
    ])
        ->assertOk()
        ->assertJsonPath('data.expires_in', 900)
        ->json('data.step_up_token');

    $this->withHeader('X-Step-Up-Token', $stepUpToken)
        ->patchJson('/api/v1/me', [
            'date_of_birth' => '1990-01-01',
        ])
        ->assertSuccessful();

    $this->withHeader('X-Step-Up-Token', $stepUpToken)
        ->patchJson('/api/v1/me', [
            'date_of_birth' => '1990-01-01',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_STEP_UP_TOKEN');

    expect($challenge->fresh()->consumed_at)->not->toBeNull()
        ->and($challenge->fresh()->step_up_token_consumed_at)->not->toBeNull();
});

test('a verified step-up proof is consumed after its first protected request', function (): void {
    $user = User::factory()->patient()->create();
    $stepUpToken = 'valid-step-up-token';
    $challenge = OtpChallenge::factory()
        ->forUser($user)
        ->purpose(OtpPurpose::SensitiveChange)
        ->state([
            'consumed_at' => now(),
            'delivery_status' => 'step_up_token_issued:'.Hash::make($stepUpToken),
        ])
        ->create();

    Sanctum::actingAs($user);

    $this->withHeader('X-Step-Up-Token', $stepUpToken)
        ->patchJson('/api/v1/me', [
            'date_of_birth' => '1990-01-01',
        ])
        ->assertSuccessful();

    $this->withHeader('X-Step-Up-Token', $stepUpToken)
        ->patchJson('/api/v1/me', [
            'date_of_birth' => '1990-01-01',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_STEP_UP_TOKEN');

    expect($challenge->fresh()->step_up_token_consumed_at)->not->toBeNull();
});

test('a step-up proof is bound to its issuing user', function (): void {
    $owner = User::factory()->patient()->create();
    $attacker = User::factory()->patient()->create();
    $stepUpToken = 'owner-step-up-token';

    OtpChallenge::factory()
        ->forUser($owner)
        ->purpose(OtpPurpose::SensitiveChange)
        ->state([
            'consumed_at' => now(),
            'delivery_status' => 'step_up_token_issued:'.Hash::make($stepUpToken),
        ])
        ->create();

    Sanctum::actingAs($attacker);

    $this->withHeader('X-Step-Up-Token', $stepUpToken)
        ->patchJson('/api/v1/me', [
            'date_of_birth' => '1990-01-01',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_STEP_UP_TOKEN');
});

test('an expired step-up proof is rejected', function (): void {
    $user = User::factory()->patient()->create();
    $stepUpToken = 'expired-step-up-token';

    OtpChallenge::factory()
        ->forUser($user)
        ->purpose(OtpPurpose::SensitiveChange)
        ->state([
            'consumed_at' => now()->subMinutes(16),
            'delivery_status' => 'step_up_token_issued:'.Hash::make($stepUpToken),
        ])
        ->create();

    Sanctum::actingAs($user);

    $this->withHeader('X-Step-Up-Token', $stepUpToken)
        ->patchJson('/api/v1/me', [
            'date_of_birth' => '1990-01-01',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_STEP_UP_TOKEN');
});

test('otp challenge purpose is constrained to known values at the database boundary', function (): void {
    expect(fn (): bool => DB::table('otp_challenges')->insert([
        'public_id' => (string) Str::uuid(),
        'user_id' => null,
        'purpose' => 'not-a-real-purpose',
        'channel' => 'email',
        'encrypted_destination' => 'not-a-real-destination',
        'destination_hash' => hash('sha256', 'not-a-real-destination'),
        'code_digest' => Hash::make('123456'),
        'attempts' => 0,
        'max_attempts' => 5,
        'expires_at' => now()->addMinutes(10),
        'delivery_status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
