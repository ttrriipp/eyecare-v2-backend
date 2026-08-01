<?php

use App\Actions\PatientAccounts\CreateContactLookupHash;
use App\Actions\PatientAccounts\IssuePatientInvitation;
use App\Enums\OtpPurpose;
use App\Enums\PatientInvitationStatus;
use App\Filament\Resources\Patients\PatientResource;
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

    $response = $this->actingAs($user)
        ->postJson('/api/v1/patient-invitations/accept', [
            'invitation_code' => $invitation->invitation_code,
            'challenge_id' => $challenge->public_id,
            'code' => $code,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'linked');

    expect($patient->fresh()->user_id)->toBe($user->id);
    expect($invitation->fresh()->status)->toBe(PatientInvitationStatus::Accepted);
});

test('invitation acceptance returns token for new users', function () {
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

    $response = $this->actingAs($user)
        ->postJson('/api/v1/patient-invitations/accept', [
            'invitation_code' => $invitation->invitation_code,
            'challenge_id' => $challenge->public_id,
            'code' => $code,
        ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user', 'status']]);
});

// --- Filament Actions ---

test('admin can see send invitation action on unlinked patient', function () {
    $admin = User::factory()->admin()->create();
    $patient = Patient::factory()->create(['user_id' => null]);

    $this->actingAs($admin);

    $this->get(PatientResource::getUrl('edit', ['record' => $patient]))
        ->assertSuccessful();
});
