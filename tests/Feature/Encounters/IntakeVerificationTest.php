<?php

use App\Actions\Intakes\VerifyPatientIntake;
use App\Enums\IntakeStatus;
use App\Models\PatientIntake;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('verification records verifier and timestamp', function () {
    $staff = User::factory()->staff()->create();
    $intake = PatientIntake::factory()->submitted()->create();

    app(VerifyPatientIntake::class)->handle($intake, $staff);

    $intake->refresh();
    expect($intake->status)->toBe(IntakeStatus::Verified)
        ->and($intake->verified_by)->toBe($staff->id)
        ->and($intake->verified_at)->not->toBeNull();
});

test('verification locks the submitted snapshot', function () {
    $staff = User::factory()->staff()->create();
    $intake = PatientIntake::factory()->submitted()->create([
        'chief_complaint' => 'Original complaint',
    ]);

    app(VerifyPatientIntake::class)->handle($intake, $staff);

    $intake->refresh();
    expect($intake->chief_complaint)->toBe('Original complaint')
        ->and($intake->status)->toBe(IntakeStatus::Verified);
});

test('verification rejects non-submitted intake', function () {
    $staff = User::factory()->staff()->create();
    $draft = PatientIntake::factory()->create(['status' => IntakeStatus::Draft]);

    app(VerifyPatientIntake::class)->handle($draft, $staff);
})->throws(ValidationException::class);

test('non-optometrist staff can verify intake', function () {
    // Non-optometrist staff CAN verify intake (operational verification).
    // This does NOT authorize clinical findings — that requires is_optometrist.
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);
    $intake = PatientIntake::factory()->submitted()->create();

    $result = app(VerifyPatientIntake::class)->handle($intake, $staff);

    expect($result->status)->toBe(IntakeStatus::Verified)
        ->and($result->verified_by)->toBe($staff->id);
});
