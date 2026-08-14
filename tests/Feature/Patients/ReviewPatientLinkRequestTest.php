<?php

use App\Actions\PatientAccounts\ReviewPatientLinkRequest;
use App\Actions\PatientAccounts\UnlinkPatientAccount;
use App\Models\AppointmentRequest;
use App\Models\Patient;
use App\Models\PatientLinkRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

// --- Approve ---

test('approval activates the patient link', function () {
    $patient = Patient::factory()->create(['user_id' => null]);
    $account = User::factory()->create(['role_id' => Role::where('name', 'patient')->first()->id]);
    $reviewer = User::factory()->staff()->create();

    $request = PatientLinkRequest::factory()->pending()->create(['user_id' => $account->id]);

    $result = app(ReviewPatientLinkRequest::class)->approve(
        linkRequest: $request,
        patient: $patient,
        reviewer: $reviewer,
    );

    expect($result->status)->toBe('approved')
        ->and($result->reviewed_patient_id)->toBe($patient->id)
        ->and($result->reviewer_id)->toBe($reviewer->id);

    expect($patient->fresh()->user_id)->toBe($account->id);
});

test('approval links the account appointment requests to the reviewed patient', function () {
    $patient = Patient::factory()->create(['user_id' => null]);
    $account = User::factory()->create(['role_id' => Role::where('name', 'patient')->first()->id]);
    $reviewer = User::factory()->staff()->create();
    $appointmentRequest = AppointmentRequest::factory()->withSnapshot()->create([
        'user_id' => $account->id,
        'patient_id' => null,
    ]);

    app(ReviewPatientLinkRequest::class)->approve(
        linkRequest: PatientLinkRequest::factory()->pending()->create(['user_id' => $account->id]),
        patient: $patient,
        reviewer: $reviewer,
    );

    expect($appointmentRequest->fresh()->patient_id)->toBe($patient->id)
        ->and($appointmentRequest->fresh()->encrypted_identity_snapshot)->toBeArray();
});

test('approval rechecks patient eligibility under lock', function () {
    $patient = Patient::factory()->create(['user_id' => null]);
    $account = User::factory()->create(['role_id' => Role::where('name', 'patient')->first()->id]);
    $reviewer = User::factory()->staff()->create();
    $request = PatientLinkRequest::factory()->pending()->create(['user_id' => $account->id]);

    // Create another user without a patient and link the patient to them
    $otherUser = User::factory()->create(['role_id' => Role::where('name', 'patient')->first()->id]);
    $patient->update(['user_id' => $otherUser->id]);

    expect(fn () => app(ReviewPatientLinkRequest::class)->approve(
        linkRequest: $request,
        patient: $patient,
        reviewer: $reviewer,
    ))->toThrow(ValidationException::class);
});

test('approval fails if account is already linked', function () {
    $patient = Patient::factory()->create(['user_id' => null]);
    $account = User::factory()->patient()->create(); // Already linked
    $reviewer = User::factory()->staff()->create();
    $request = PatientLinkRequest::factory()->pending()->create(['user_id' => $account->id]);

    expect(fn () => app(ReviewPatientLinkRequest::class)->approve(
        linkRequest: $request,
        patient: $patient,
        reviewer: $reviewer,
    ))->toThrow(ValidationException::class);
});

// --- Reject ---

test('reject closes the request without linking', function () {
    $account = User::factory()->create(['role_id' => Role::where('name', 'patient')->first()->id]);
    $reviewer = User::factory()->staff()->create();
    $request = PatientLinkRequest::factory()->pending()->create(['user_id' => $account->id]);

    $result = app(ReviewPatientLinkRequest::class)->reject(
        linkRequest: $request,
        reviewer: $reviewer,
        note: 'No matching patient found',
    );

    expect($result->status)->toBe('rejected')
        ->and($result->reviewer_id)->toBe($reviewer->id)
        ->and($result->decision_note)->toBe('No matching patient found');
});

// --- Unlink ---

test('unlinking revokes tokens and removes link', function () {
    $account = User::factory()->patient()->create();
    $patient = $account->patient;
    $admin = User::factory()->admin()->create();

    $account->createToken('device-1');
    $account->createToken('device-2');

    app(UnlinkPatientAccount::class)->handle(
        patient: $patient,
        admin: $admin,
        reason: 'Patient requested unlinking',
    );

    expect($patient->fresh()->user_id)->toBeNull()
        ->and($account->fresh()->tokens()->count())->toBe(0);
});

test('unlinking creates audit log', function () {
    $account = User::factory()->patient()->create();
    $patient = $account->patient;
    $admin = User::factory()->admin()->create();

    app(UnlinkPatientAccount::class)->handle(
        patient: $patient,
        admin: $admin,
        reason: 'Patient requested unlinking',
    );

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'patient_account_unlinked',
    ]);
});
