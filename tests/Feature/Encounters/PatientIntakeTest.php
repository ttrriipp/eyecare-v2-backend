<?php

use App\Enums\IntakeStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\PatientIntake;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('intake belongs to a patient', function () {
    $patient = Patient::factory()->create();
    $intake = PatientIntake::factory()->create(['patient_id' => $patient->id]);

    expect($intake->patient->id)->toBe($patient->id);
});

test('intake may optionally belong to an appointment', function () {
    $appointment = Appointment::factory()->create();
    $intake = PatientIntake::factory()->linkedToAppointment($appointment)->create();

    expect($intake->appointment_id)->toBe($appointment->id)
        ->and($intake->appointment->id)->toBe($appointment->id);
});

test('intake can exist without an appointment', function () {
    $intake = PatientIntake::factory()->create(['appointment_id' => null]);

    expect($intake->appointment_id)->toBeNull()
        ->and($intake->appointment)->toBeNull();
});

test('clinical narrative fields are encrypted', function () {
    $intake = PatientIntake::factory()->create([
        'chief_complaint' => 'Blurred vision in left eye',
        'past_ocular_history' => 'Previous cataract surgery',
        'allergies' => 'Penicillin',
    ]);

    // Encrypted values in the database are not the plain text
    $raw = DB::table('patient_intakes')
        ->where('id', $intake->id)
        ->first();

    expect($raw->chief_complaint)->not->toBe('Blurred vision in left eye')
        ->and($raw->chief_complaint)->not->toBeNull()
        ->and($raw->past_ocular_history)->not->toBe('Previous cataract surgery')
        ->and($raw->allergies)->not->toBe('Penicillin');

    // But the model decrypts them transparently
    $fresh = PatientIntake::find($intake->id);
    expect($fresh->chief_complaint)->toBe('Blurred vision in left eye')
        ->and($fresh->past_ocular_history)->toBe('Previous cataract surgery')
        ->and($fresh->allergies)->toBe('Penicillin');
});

test('intake defaults to draft status', function () {
    $intake = PatientIntake::factory()->create();

    expect($intake->status)->toBe(IntakeStatus::Draft);
});

test('intake status can be draft submitted or verified', function () {
    $draft = PatientIntake::factory()->create(['status' => IntakeStatus::Draft]);
    $submitted = PatientIntake::factory()->submitted()->create();
    $verified = PatientIntake::factory()->verified()->create();

    expect($draft->status)->toBe(IntakeStatus::Draft)
        ->and($submitted->status)->toBe(IntakeStatus::Submitted)
        ->and($verified->status)->toBe(IntakeStatus::Verified);
});

test('submitted intake records submitter and timestamp', function () {
    $staff = User::factory()->staff()->create();
    $intake = PatientIntake::factory()->create([
        'status' => IntakeStatus::Submitted,
        'submitted_by' => $staff->id,
        'submitted_at' => now(),
    ]);

    expect($intake->submitted_by)->toBe($staff->id)
        ->and($intake->submitted_at)->not->toBeNull()
        ->and($intake->submittedBy->id)->toBe($staff->id);
});

test('verified intake records verifier and timestamp', function () {
    $staff = User::factory()->staff()->create();
    $intake = PatientIntake::factory()->verified()->create([
        'verified_by' => $staff->id,
    ]);

    expect($intake->verified_by)->toBe($staff->id)
        ->and($intake->verified_at)->not->toBeNull()
        ->and($intake->verifiedBy->id)->toBe($staff->id);
});

test('factory states produce valid records', function () {
    $draft = PatientIntake::factory()->create();
    $submitted = PatientIntake::factory()->submitted()->create();
    $verified = PatientIntake::factory()->verified()->create();

    expect($draft->status)->toBe(IntakeStatus::Draft)
        ->and($draft->submitted_by)->toBeNull()
        ->and($submitted->status)->toBe(IntakeStatus::Submitted)
        ->and($submitted->submitted_by)->not->toBeNull()
        ->and($verified->status)->toBe(IntakeStatus::Verified)
        ->and($verified->verified_by)->not->toBeNull();
});

test('intake snapshot includes patient demographics', function () {
    $intake = PatientIntake::factory()->create([
        'full_name' => 'Juan dela Cruz',
        'date_of_birth' => '1990-05-15',
        'gender' => 'male',
        'occupation' => 'Engineer',
        'address' => '123 Rizal St, Quezon City',
        'phone' => '09171234567',
        'email' => 'juan@example.com',
    ]);

    expect($intake->full_name)->toBe('Juan dela Cruz')
        ->and($intake->date_of_birth->toDateString())->toBe('1990-05-15')
        ->and($intake->gender)->toBe('male')
        ->and($intake->occupation)->toBe('Engineer')
        ->and($intake->address)->toBe('123 Rizal St, Quezon City')
        ->and($intake->phone)->toBe('09171234567')
        ->and($intake->email)->toBe('juan@example.com');
});
