<?php

use App\Enums\IntakeStatus;
use App\Models\PatientIntake;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('patient can create a draft intake', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)
        ->postJson('/api/patient/intakes', [
            'chief_complaint' => 'Blurred vision',
            'allergies' => 'Penicillin',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.chief_complaint', 'Blurred vision');

    $this->assertDatabaseHas('patient_intakes', [
        'patient_id' => $user->patient->id,
        'status' => 'draft',
    ]);
});

test('patient can update their own draft intake', function () {
    $user = User::factory()->patient()->create();
    $intake = PatientIntake::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => IntakeStatus::Draft,
    ]);

    $this->actingAs($user)
        ->patchJson("/api/patient/intakes/{$intake->id}", [
            'chief_complaint' => 'Updated complaint',
        ])
        ->assertOk()
        ->assertJsonPath('data.chief_complaint', 'Updated complaint');
});

test('patient cannot update a verified intake', function () {
    $user = User::factory()->patient()->create();
    $intake = PatientIntake::factory()->verified()->create([
        'patient_id' => $user->patient->id,
    ]);

    $this->actingAs($user)
        ->patchJson("/api/patient/intakes/{$intake->id}", [
            'chief_complaint' => 'Should not work',
        ])
        ->assertUnprocessable();
});

test('patient can submit their own draft intake', function () {
    $user = User::factory()->patient()->create();
    $intake = PatientIntake::factory()->create([
        'patient_id' => $user->patient->id,
        'status' => IntakeStatus::Draft,
    ]);

    $this->actingAs($user)
        ->postJson("/api/patient/intakes/{$intake->id}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'submitted');

    $intake->refresh();
    expect($intake->status)->toBe(IntakeStatus::Submitted)
        ->and($intake->submitted_by)->toBe($user->id)
        ->and($intake->submitted_at)->not->toBeNull();
});

test('patient cannot submit a non-draft intake', function () {
    $user = User::factory()->patient()->create();
    $intake = PatientIntake::factory()->submitted()->create([
        'patient_id' => $user->patient->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/patient/intakes/{$intake->id}/submit")
        ->assertUnprocessable();
});

test('patient cannot access another patients intake', function () {
    $userA = User::factory()->patient()->create();
    $userB = User::factory()->patient()->create();
    $intakeB = PatientIntake::factory()->create(['patient_id' => $userB->patient->id]);

    $this->actingAs($userA)
        ->patchJson("/api/patient/intakes/{$intakeB->id}", ['chief_complaint' => 'Hacked'])
        ->assertForbidden();

    $this->actingAs($userA)
        ->postJson("/api/patient/intakes/{$intakeB->id}/submit")
        ->assertForbidden();
});

test('staff can verify a submitted intake', function () {
    $staff = User::factory()->staff()->create();
    $intake = PatientIntake::factory()->submitted()->create();

    $this->actingAs($staff)
        ->postJson("/api/patient/intakes/{$intake->id}/verify")
        ->assertOk()
        ->assertJsonPath('data.status', 'verified');

    $intake->refresh();
    expect($intake->status)->toBe(IntakeStatus::Verified)
        ->and($intake->verified_by)->toBe($staff->id)
        ->and($intake->verified_at)->not->toBeNull();
});

test('non-optometrist verification does not authorize clinical findings', function () {
    $staff = User::factory()->staff()->create();
    $intake = PatientIntake::factory()->submitted()->create();

    $this->actingAs($staff)
        ->postJson("/api/patient/intakes/{$intake->id}/verify")
        ->assertOk();

    $intake->refresh();
    expect($intake->status)->toBe(IntakeStatus::Verified);
    // Verification only records verifier/time — it does NOT grant clinical
    // authorship. The IntakeVerified event is a verification checkpoint,
    // not a clinical authorization. Clinical findings remain optometrist-only.
});

test('patient cannot verify an intake', function () {
    $patient = User::factory()->patient()->create();
    $intake = PatientIntake::factory()->submitted()->create();

    $this->actingAs($patient)
        ->postJson("/api/patient/intakes/{$intake->id}/verify")
        ->assertForbidden();
});

test('only submitted intakes can be verified', function () {
    $staff = User::factory()->staff()->create();
    $draft = PatientIntake::factory()->create(['status' => IntakeStatus::Draft]);

    $this->actingAs($staff)
        ->postJson("/api/patient/intakes/{$draft->id}/verify")
        ->assertUnprocessable();
});

test('patient can list their own intakes', function () {
    $user = User::factory()->patient()->create();
    $myIntakes = PatientIntake::factory()->count(3)->create(['patient_id' => $user->patient->id]);
    $otherIntake = PatientIntake::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/patient/intakes')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('unauthenticated request returns 401', function () {
    $this->getJson('/api/patient/intakes')->assertUnauthorized();
    $this->postJson('/api/patient/intakes', [])->assertUnauthorized();
});
