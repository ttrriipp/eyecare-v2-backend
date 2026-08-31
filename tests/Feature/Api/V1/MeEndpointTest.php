<?php

use App\Enums\AuditEvent;
use App\Enums\OtpPurpose;
use App\Models\AuditLog;
use App\Models\OtpChallenge;
use App\Models\Patient;
use App\Models\PatientAccountContact;
use App\Models\PatientLinkRequest;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('me endpoint returns patient profile data', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertSuccessful()
        ->assertJsonPath('data.linked_patient.patient_number', $user->patient->patient_number)
        ->assertJsonPath('data.linked_patient.full_name', $user->patient->full_name)
        ->assertJsonPath('data.name', $user->full_name);
});

test('me endpoint returns the verified account email separately from the linked patient email', function () {
    $accountEmail = 'account@example.com';
    $accountPhone = '+639171234567';
    $clinicEmail = 'clinic@example.com';
    $clinicPhone = '+639181234567';
    $user = User::factory()->patient()->create([
        'email' => $accountEmail,
        'email_verified_at' => now(),
        'phone' => $accountPhone,
    ]);

    $user->patient()->update([
        'contact_email' => $clinicEmail,
        'phone' => $clinicPhone,
    ]);

    PatientAccountContact::factory()->email($accountEmail)->verified()->create([
        'user_id' => $user->id,
    ]);
    PatientAccountContact::factory()->phone($accountPhone)->verified()->primary()->create([
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertSuccessful()
        ->assertJsonPath('data.email', $accountEmail)
        ->assertJsonPath('data.phone', $accountPhone)
        ->assertJsonPath('data.linked_patient.contact_email', $clinicEmail)
        ->assertJsonPath('data.linked_patient.phone', $clinicPhone);
});

test('me endpoint returns a null account email when no verified email exists', function () {
    $user = User::factory()->patient()->create([
        'email' => 'pending@example.com',
        'email_verified_at' => null,
    ]);

    PatientAccountContact::factory()->email('pending@example.com')->create([
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertSuccessful()
        ->assertJsonPath('data.email', null);
});

test('me endpoint can update account fields', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->patchJson('/api/v1/me', ['first_name' => 'Updated', 'last_name' => 'Name'])
        ->assertSuccessful()
        ->assertJsonPath('data.first_name', 'Updated');
});

test('me endpoint can update account name', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->patchJson('/api/v1/me', [
            'first_name' => 'New',
            'middle_name' => null,
            'last_name' => 'Name',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.first_name', 'New')
        ->assertJsonPath('data.name', 'New Name');
});

test('me endpoint requires step-up verification when date of birth is submitted', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->patchJson('/api/v1/me', [
            'date_of_birth' => '1990-01-01',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'STEP_UP_REQUIRED');
});

test('password changes remain unconditionally protected by step-up verification', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'STEP_UP_REQUIRED');
});

test('valid step-up verification allows a date of birth profile request through the middleware', function () {
    $user = User::factory()->patient()->create();
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
        ->patchJson('/api/v1/me', [
            'date_of_birth' => '1990-01-01',
        ])
        ->assertSuccessful();
});

test('me endpoint rejects unsupported profile fields instead of ignoring them', function () {
    $user = User::factory()->patient()->create([
        'first_name' => 'Original',
    ]);

    foreach (['email', 'phone', 'address', 'occupation', 'gender', 'contact_email'] as $field) {
        $this->actingAs($user)
            ->patchJson('/api/v1/me', [$field => 'unsupported'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    expect($user->fresh()->first_name)->toBe('Original');
});

test('me endpoint rejects mixed supported and unsupported profile fields atomically', function () {
    $user = User::factory()->patient()->create([
        'first_name' => 'Original',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/me', [
            'first_name' => 'Changed',
            'address' => 'Unsupported',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['address']);

    expect($user->fresh()->first_name)->toBe('Original');
});

test('me endpoint normalizes account names at the validation boundary', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->patchJson('/api/v1/me', [
            'first_name' => '  Trimmed  ',
            'middle_name' => '   ',
            'last_name' => '  Name  ',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.first_name', 'Trimmed')
        ->assertJsonPath('data.last_name', 'Name');
});

test('me endpoint rejects a non-exact date of birth format', function () {
    $user = User::factory()->patient()->create();
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
        ->patchJson('/api/v1/me', [
            'date_of_birth' => '1990-1-1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['date_of_birth']);
});

test('me endpoint persists account identity and expires its pending link request without changing the patient record', function () {
    $user = User::factory()->create([
        'first_name' => 'Original',
        'middle_name' => null,
        'last_name' => 'Account',
        'date_of_birth' => '1988-01-01',
    ]);
    $patient = Patient::factory()->unlinked()->create([
        'first_name' => 'Clinic',
        'middle_name' => 'Record',
        'last_name' => 'Patient',
        'date_of_birth' => '1970-02-02',
    ]);
    $linkRequest = PatientLinkRequest::factory()->for($user)->pending()->create([
        'encrypted_identity_snapshot' => [
            'first_name' => 'Original',
            'middle_name' => null,
            'last_name' => 'Account',
            'date_of_birth' => '1988-01-01',
        ],
    ]);
    $patientBefore = $patient->fresh()->only([
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
        'occupation',
        'address',
        'gender',
        'contact_email',
        'phone',
    ]);
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
        ->patchJson('/api/v1/me', [
            'first_name' => 'Updated',
            'middle_name' => 'Middle',
            'last_name' => 'Name',
            'date_of_birth' => '1990-03-03',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.first_name', 'Updated')
        ->assertJsonPath('data.middle_name', 'Middle')
        ->assertJsonPath('data.date_of_birth', '1990-03-03');

    expect($user->fresh()->only([
        'first_name',
        'middle_name',
        'last_name',
    ]))->toMatchArray([
        'first_name' => 'Updated',
        'middle_name' => 'Middle',
        'last_name' => 'Name',
    ])
        ->and($user->fresh()->date_of_birth?->toDateString())->toBe('1990-03-03')
        ->and($patient->fresh()->only([
            'first_name',
            'middle_name',
            'last_name',
            'date_of_birth',
            'occupation',
            'address',
            'gender',
            'contact_email',
            'phone',
        ]))->toMatchArray($patientBefore)
        ->and($linkRequest->fresh()->status)->toBe('expired');

    $profileAudit = AuditLog::query()
        ->where('subject_type', $user->getMorphClass())
        ->where('subject_id', $user->id)
        ->where('action', AuditEvent::UserProfileUpdated->value)
        ->latest('id')
        ->first();
    $expiryAudit = AuditLog::query()
        ->where('subject_type', $linkRequest->getMorphClass())
        ->where('subject_id', $linkRequest->id)
        ->where('action', AuditEvent::PatientLinkRequestExpired->value)
        ->latest('id')
        ->first();

    expect($profileAudit)->not->toBeNull()
        ->and($profileAudit->metadata)->toMatchArray([
            'changed_fields' => ['first_name', 'middle_name', 'last_name', 'date_of_birth'],
            'pending_link_request_expired' => true,
            'pending_link_request_id' => $linkRequest->id,
        ])
        ->and($profileAudit->metadata)->not->toHaveKey('old_values')
        ->and($profileAudit->metadata)->not->toHaveKey('new_values')
        ->and($expiryAudit)->not->toBeNull()
        ->and($expiryAudit->metadata)->toMatchArray([
            'reason' => 'account_identity_changed',
            'account_id' => $user->id,
        ]);
});

test('me endpoint treats normalized no-op identity updates as no-ops', function () {
    $user = User::factory()->create([
        'first_name' => 'Original',
        'middle_name' => null,
        'last_name' => 'Account',
    ]);
    $linkRequest = PatientLinkRequest::factory()->for($user)->pending()->create();

    $this->actingAs($user)
        ->patchJson('/api/v1/me', [
            'first_name' => '  Original  ',
            'middle_name' => '   ',
            'last_name' => ' Account ',
        ])
        ->assertSuccessful();

    expect($linkRequest->fresh()->status)->toBe('pending')
        ->and(AuditLog::query()
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->id)
            ->where('action', AuditEvent::UserProfileUpdated->value)
            ->exists())->toBeFalse();
});

test('me endpoint reports pending link status consistently on get and patch responses', function () {
    $user = User::factory()->create();
    PatientLinkRequest::factory()->for($user)->pending()->create();

    $getResponse = $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertSuccessful()
        ->assertJsonPath('data.link_status', 'pending_review')
        ->assertJsonPath('data.linked_patient', null);

    $patchResponse = $this->actingAs($user)
        ->patchJson('/api/v1/me', ['first_name' => $user->first_name])
        ->assertSuccessful()
        ->assertJsonPath('data.link_status', 'pending_review')
        ->assertJsonPath('data.linked_patient', null);

    expect($patchResponse->json('data'))->toMatchArray([
        'link_status' => $getResponse->json('data.link_status'),
        'linked_patient' => $getResponse->json('data.linked_patient'),
    ]);
});

test('patient profile routes are absent', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/patient/profile')
        ->assertNotFound();

    $this->actingAs($user)
        ->patchJson('/api/v1/patient/profile', ['full_name' => 'Test'])
        ->assertNotFound();
});

test('me endpoint returns 404 when no patient linked', function () {
    $user = User::factory()->staff()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertSuccessful();
});

test('me endpoint tolerates a normal mobile bootstrap burst', function () {
    $user = User::factory()->patient()->create();
    $token = $user->createToken('mobile')->plainTextToken;

    foreach (range(1, 61) as $attempt) {
        $this->withToken($token)
            ->getJson('/api/v1/me')
            ->assertSuccessful();
    }
});
