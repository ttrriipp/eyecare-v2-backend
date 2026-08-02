<?php

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Enums\OtpPurpose;
use App\Models\OtpChallenge;
use App\Models\PatientAccountContact;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('verifying an email contact updates the account email verification state', function () {
    $email = 'user@example.com';
    $user = User::factory()->patient()->create();
    $pendingContact = PatientAccountContact::factory()->email($email)->create([
        'user_id' => $user->id,
    ]);
    $code = '123456';
    $challenge = OtpChallenge::factory()->forUser($user)->pending()->create([
        'purpose' => OtpPurpose::AddContact,
        'channel' => 'email',
        'encrypted_destination' => $email,
        'destination_hash' => $pendingContact->lookup_hash,
        'code_digest' => Hash::make($code),
    ]);

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/account/contacts/verify', [
        'challenge_id' => $challenge->public_id,
        'code' => $code,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.type', 'email');

    expect($pendingContact->fresh()->verified_at)->not->toBeNull()
        ->and($user->fresh()->email)->toBe($email)
        ->and($user->fresh()->email_verified_at)->not->toBeNull();
});

test('contact verification cannot claim another user\'s challenge', function () {
    $owner = User::factory()->patient()->create();
    $attacker = User::factory()->patient()->create();
    $challenge = OtpChallenge::factory()->forUser($owner)->pending()->create([
        'purpose' => OtpPurpose::AddContact,
        'channel' => 'email',
        'encrypted_destination' => 'owner@example.com',
        'destination_hash' => app(CreateContactLookupHash::class)
            ->forEmail('owner@example.com'),
        'code_digest' => Hash::make('123456'),
    ]);

    Sanctum::actingAs($attacker);

    $response = $this->postJson('/api/v1/account/contacts/verify', [
        'challenge_id' => $challenge->public_id,
        'code' => '123456',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['challenge_id']);

    expect($challenge->fresh()->consumed_at)->toBeNull()
        ->and($attacker->contacts()->count())->toBe(0);
});
