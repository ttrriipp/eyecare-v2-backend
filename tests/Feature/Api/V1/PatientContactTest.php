<?php

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Enums\AuditEvent;
use App\Enums\OtpPurpose;
use App\Models\AuditLog;
use App\Models\OtpChallenge;
use App\Models\PatientAccountContact;
use App\Models\PatientLinkRequest;
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
    $phone = '+639171234567';
    $user = User::factory()->patient()->create([
        'email' => null,
        'email_verified_at' => null,
        'phone' => $phone,
    ]);
    PatientAccountContact::factory()->phone($phone)->verified()->primary()->create([
        'user_id' => $user->id,
    ]);
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

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.email', $email)
        ->assertJsonPath('data.phone', $phone);

    expect($pendingContact->fresh()->verified_at)->not->toBeNull()
        ->and($user->fresh()->email)->toBe($email)
        ->and($user->fresh()->email_verified_at)->not->toBeNull();
});

test('account contacts expose masked values without raw contact values', function () {
    $email = 'user@example.com';
    $phone = '+639171234567';
    $user = User::factory()->create([
        'email' => $email,
        'email_verified_at' => now(),
        'phone' => $phone,
    ]);

    PatientAccountContact::factory()->email($email)->verified()->create([
        'user_id' => $user->id,
    ]);
    PatientAccountContact::factory()->phone($phone)->verified()->primary()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/account/contacts')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2);

    foreach ($response->json('data') as $contact) {
        expect($contact)
            ->toHaveKeys(['id', 'type', 'masked_value', 'is_primary', 'verified_at'])
            ->not->toHaveKey('encrypted_value')
            ->not->toHaveKey('value');
    }

    $response
        ->assertJsonPath('data.0.masked_value', '+63***4567')
        ->assertJsonPath('data.1.masked_value', 'u***@example.com');
});

test('wrong contact verification code increments attempts without mutating the account', function () {
    $user = User::factory()->create();
    $linkRequest = PatientLinkRequest::factory()->for($user)->pending()->create();
    $challenge = OtpChallenge::factory()->forUser($user)->pending()->create([
        'purpose' => OtpPurpose::AddContact,
        'channel' => 'email',
        'encrypted_destination' => 'new@example.com',
        'destination_hash' => app(CreateContactLookupHash::class)
            ->forEmail('new@example.com'),
        'code_digest' => Hash::make('123456'),
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/account/contacts/verify', [
        'challenge_id' => $challenge->public_id,
        'code' => '999999',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    expect($challenge->fresh()->attempts)->toBe(1)
        ->and($challenge->fresh()->invalidated_at)->toBeNull()
        ->and($linkRequest->fresh()->status)->toBe('pending')
        ->and($user->fresh()->email)->not->toBe('new@example.com');
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

test('verifying a changed contact expires a pending patient link request', function () {
    $user = User::factory()->create();
    $existingContact = PatientAccountContact::factory()
        ->email('old@example.com')
        ->verified()
        ->primary()
        ->create(['user_id' => $user->id]);
    $linkRequest = PatientLinkRequest::factory()->for($user)->pending()->create();
    $code = '123456';
    $newEmail = 'new@example.com';
    $lookupHash = app(CreateContactLookupHash::class);
    $challenge = OtpChallenge::factory()->forUser($user)->pending()->create([
        'purpose' => OtpPurpose::AddContact,
        'channel' => 'email',
        'encrypted_destination' => $newEmail,
        'destination_hash' => $lookupHash->forEmail($newEmail),
        'code_digest' => Hash::make($code),
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/account/contacts/verify', [
            'challenge_id' => $challenge->public_id,
            'code' => $code,
        ])
        ->assertOk();

    expect($existingContact->fresh()->lookup_hash)->toBe($lookupHash->forEmail($newEmail))
        ->and($linkRequest->fresh()->status)->toBe('expired')
        ->and(AuditLog::query()
            ->where('subject_type', $linkRequest->getMorphClass())
            ->where('subject_id', $linkRequest->id)
            ->where('action', AuditEvent::PatientLinkRequestExpired->value)
            ->exists())->toBeTrue();
});

test('verifying the same verified contact does not expire a pending patient link request', function () {
    $user = User::factory()->create();
    $email = 'same@example.com';
    $contact = PatientAccountContact::factory()
        ->email($email)
        ->verified()
        ->primary()
        ->create(['user_id' => $user->id]);
    $linkRequest = PatientLinkRequest::factory()->for($user)->pending()->create();
    $code = '123456';
    $challenge = OtpChallenge::factory()->forUser($user)->pending()->create([
        'purpose' => OtpPurpose::AddContact,
        'channel' => 'email',
        'encrypted_destination' => $email,
        'destination_hash' => $contact->lookup_hash,
        'code_digest' => Hash::make($code),
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/account/contacts/verify', [
            'challenge_id' => $challenge->public_id,
            'code' => $code,
        ])
        ->assertOk();

    expect($linkRequest->fresh()->status)->toBe('pending')
        ->and(AuditLog::query()
            ->where('subject_type', $linkRequest->getMorphClass())
            ->where('subject_id', $linkRequest->id)
            ->where('action', AuditEvent::PatientLinkRequestExpired->value)
            ->exists())->toBeFalse();
});

test('removing a verified contact expires a pending patient link request', function () {
    $user = User::factory()->create();
    PatientAccountContact::factory()->email('primary@example.com')->verified()->primary()->create([
        'user_id' => $user->id,
    ]);
    $contact = PatientAccountContact::factory()->phone('+639171234567')->verified()->create([
        'user_id' => $user->id,
    ]);
    $linkRequest = PatientLinkRequest::factory()->for($user)->pending()->create();
    $token = 'valid-step-up-token';

    OtpChallenge::factory()
        ->forUser($user)
        ->purpose(OtpPurpose::SensitiveChange)
        ->state([
            'consumed_at' => now(),
            'delivery_status' => 'step_up_token_issued:'.Hash::make($token),
        ])
        ->create();

    $this->actingAs($user)
        ->withHeader('X-Step-Up-Token', $token)
        ->deleteJson('/api/v1/account/contacts/'.$contact->id)
        ->assertNoContent();

    expect($linkRequest->fresh()->status)->toBe('expired');
});

test('changing only the primary contact does not expire a pending patient link request', function () {
    $user = User::factory()->create();
    PatientAccountContact::factory()->email('primary@example.com')->verified()->primary()->create([
        'user_id' => $user->id,
    ]);
    $contact = PatientAccountContact::factory()->phone('+639171234567')->verified()->create([
        'user_id' => $user->id,
    ]);
    $linkRequest = PatientLinkRequest::factory()->for($user)->pending()->create();
    $token = 'valid-step-up-token';

    OtpChallenge::factory()
        ->forUser($user)
        ->purpose(OtpPurpose::SensitiveChange)
        ->state([
            'consumed_at' => now(),
            'delivery_status' => 'step_up_token_issued:'.Hash::make($token),
        ])
        ->create();

    $this->actingAs($user)
        ->withHeader('X-Step-Up-Token', $token)
        ->patchJson('/api/v1/account/contacts/'.$contact->id.'/primary')
        ->assertOk();

    expect($linkRequest->fresh()->status)->toBe('pending');
});
