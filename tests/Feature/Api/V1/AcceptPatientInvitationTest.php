<?php

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\IssuePatientInvitation;
use App\Enums\OtpPurpose;
use App\Enums\PatientInvitationStatus;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Conversation;
use App\Models\OtpChallenge;
use App\Models\Patient;
use App\Models\PatientAccountContact;
use App\Models\PatientInvitation;
use App\Models\Role;
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

// --- Invitation OTP Request ---

test('invitation OTP request returns challenge for valid invitation', function () {
    $patient = Patient::factory()->create([
        'contact_email' => 'patient@example.com',
    ]);

    $staff = User::factory()->staff()->create();

    $invitation = app(IssuePatientInvitation::class)->handle(
        patient: $patient,
        channel: 'email',
        sender: $staff,
    );

    $user = User::factory()->patient()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/patient-invitations/acceptance/otp', [
            'invitation_code' => $invitation->invitation_code,
        ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['challenge_id', 'expires_at']]);
});

test('invitation OTP request rejects invalid code', function () {
    $user = User::factory()->patient()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/patient-invitations/acceptance/otp', [
            'invitation_code' => 'INVALID1',
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVITATION_INVALID');
});

test('invitation OTP request rejects expired invitation', function () {
    $patient = Patient::factory()->create();
    $staff = User::factory()->staff()->create();

    $invitation = PatientInvitation::factory()->expired()->create([
        'patient_id' => $patient->id,
        'sender_id' => $staff->id,
    ]);

    $user = User::factory()->patient()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/patient-invitations/acceptance/otp', [
            'invitation_code' => $invitation->invitation_code,
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVITATION_INVALID');
});

// --- Invitation Acceptance ---

test('invitation acceptance activates patient link', function () {
    $lookupHash = app(CreateContactLookupHash::class);

    $patient = Patient::factory()->create([
        'contact_email' => 'patient@example.com',
        'contact_email_lookup_hash' => $lookupHash->forEmail('patient@example.com'),
        'user_id' => null,
    ]);

    $staff = User::factory()->staff()->create();

    $invitation = app(IssuePatientInvitation::class)->handle(
        patient: $patient,
        channel: 'email',
        sender: $staff,
    );

    // Create OTP challenge for the invitation
    $code = '123456';
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::InvitationAcceptance,
        'channel' => 'email',
        'encrypted_destination' => 'patient@example.com',
        'destination_hash' => $lookupHash->forEmail('patient@example.com'),
    ]);

    // Create an account to accept with
    $user = User::factory()->create([
        'role_id' => Role::where('name', 'patient')->first()->id,
    ]);

    // Add the contact to the user
    PatientAccountContact::factory()->email('patient@example.com')->verified()->primary()->create([
        'user_id' => $user->id,
    ]);
    $conversation = Conversation::query()->create([
        'account_user_id' => $user->id,
        'patient_id' => null,
    ]);
    $challenge->update(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/patient-invitations/accept', [
            'invitation_code' => $invitation->invitation_code,
            'challenge_id' => $challenge->public_id,
            'code' => $code,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'linked');

    expect($patient->fresh()->user_id)->toBe($user->id);
    expect($conversation->fresh()->patient_id)->toBe($patient->id);
    expect($invitation->fresh()->status)->toBe(PatientInvitationStatus::Accepted);
});

test('invitation acceptance returns a token for an unlinked account', function () {
    $lookupHash = app(CreateContactLookupHash::class);

    $patient = Patient::factory()->create([
        'contact_email' => 'newuser@example.com',
        'contact_email_lookup_hash' => $lookupHash->forEmail('newuser@example.com'),
        'user_id' => null,
    ]);

    $staff = User::factory()->staff()->create();

    $invitation = app(IssuePatientInvitation::class)->handle(
        patient: $patient,
        channel: 'email',
        sender: $staff,
    );

    $code = '123456';
    $challenge = OtpChallenge::factory()->pending()->create([
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::InvitationAcceptance,
        'channel' => 'email',
        'encrypted_destination' => 'newuser@example.com',
        'destination_hash' => $lookupHash->forEmail('newuser@example.com'),
    ]);

    // Create a user to authenticate with
    $user = User::factory()->create([
        'role_id' => Role::where('name', 'patient')->first()->id,
    ]);
    PatientAccountContact::factory()->email('newuser@example.com')->verified()->primary()->create([
        'user_id' => $user->id,
    ]);
    $challenge->update(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/patient-invitations/accept', [
            'invitation_code' => $invitation->invitation_code,
            'challenge_id' => $challenge->public_id,
            'code' => $code,
        ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user', 'status']]);
});

test('invitation acceptance is idempotent for the authenticated account and keeps the token linked', function () {
    $lookupHash = app(CreateContactLookupHash::class);
    $email = 'repeat@example.com';

    $patient = Patient::factory()->create([
        'contact_email' => $email,
        'contact_email_lookup_hash' => $lookupHash->forEmail($email),
        'user_id' => null,
    ]);

    $staff = User::factory()->staff()->create();
    $invitation = app(IssuePatientInvitation::class)->handle(
        patient: $patient,
        channel: 'email',
        sender: $staff,
    );

    $user = User::factory()->create([
        'role_id' => Role::where('name', 'patient')->first()->id,
    ]);

    PatientAccountContact::factory()->email($email)->verified()->primary()->create([
        'user_id' => $user->id,
    ]);

    $code = '123456';
    $challenge = OtpChallenge::factory()->pending()->create([
        'user_id' => $user->id,
        'code_digest' => Hash::make($code),
        'purpose' => OtpPurpose::InvitationAcceptance,
        'channel' => 'email',
        'encrypted_destination' => $email,
        'destination_hash' => $lookupHash->forEmail($email),
    ]);
    $token = $user->createToken('mobile')->plainTextToken;
    $payload = [
        'invitation_code' => $invitation->invitation_code,
        'challenge_id' => $challenge->public_id,
        'code' => $code,
    ];

    $this->withToken($token)
        ->postJson('/api/v1/patient-invitations/accept', $payload)
        ->assertOk()
        ->assertJsonPath('data.status', 'linked')
        ->assertJsonPath('data.user.link_status', 'linked');

    $this->withToken($token)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.link_status', 'linked')
        ->assertJsonPath('data.linked_patient.patient_number', $patient->patient_number);

    $this->withToken($token)
        ->postJson('/api/v1/patient-invitations/accept', $payload)
        ->assertOk()
        ->assertJsonPath('data.status', 'linked');

    expect($patient->fresh()->user_id)->toBe($user->id)
        ->and($invitation->fresh()->status)->toBe(PatientInvitationStatus::Accepted)
        ->and($invitation->fresh()->accepted_by_user_id)->toBe($user->id)
        ->and($challenge->fresh()->consumed_at)->not->toBeNull();
});

test('invitation OTP throttling returns a machine-readable response with retry information', function () {
    $patient = Patient::factory()->create([
        'contact_email' => 'rate-limit@example.com',
    ]);
    $staff = User::factory()->staff()->create();
    $invitation = app(IssuePatientInvitation::class)->handle(
        patient: $patient,
        channel: 'email',
        sender: $staff,
    );
    $user = User::factory()->create([
        'role_id' => Role::where('name', 'patient')->first()->id,
    ]);
    $token = $user->createToken('mobile')->plainTextToken;

    foreach (range(1, 5) as $attempt) {
        $this->withToken($token)
            ->postJson('/api/v1/patient-invitations/acceptance/otp', [
                'invitation_code' => $invitation->invitation_code,
            ])
            ->assertOk();
    }

    $response = $this->withToken($token)
        ->postJson('/api/v1/patient-invitations/acceptance/otp', [
            'invitation_code' => $invitation->invitation_code,
        ]);

    $response->assertTooManyRequests()
        ->assertJsonPath('error.code', 'OTP_RATE_LIMIT_REACHED');

    expect($response->headers->get('Retry-After'))->not->toBeNull();
});

// --- Filament Actions ---

test('admin can see send invitation action on unlinked patient', function () {
    $admin = User::factory()->admin()->create();
    $patient = Patient::factory()->create(['user_id' => null]);

    $this->actingAs($admin);

    $this->get(PatientResource::getUrl('edit', ['record' => $patient]))
        ->assertSuccessful();
});
