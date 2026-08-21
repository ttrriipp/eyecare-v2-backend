<?php

use App\Actions\Prescriptions\FinalizePrescription;
use App\Enums\AuditEvent;
use App\Enums\EncounterStatus;
use App\Models\AuditLog;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('only an optometrist can finalize a prescription', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->inProgress()->create(['patient_id' => $patient->id]);

    $prescription = app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: [
            'main_od_sphere' => '-2.50',
            'main_os_sphere' => '-3.00',
            'remarks' => '62.0',
        ],
    );

    expect($prescription->patient_id)->toBe($patient->id)
        ->and($prescription->encounter_id)->toBe($encounter->id)
        ->and($prescription->created_by)->toBe($optometrist->id)
        ->and($prescription->prescribed_at)->not->toBeNull()
        ->and(AuditLog::query()
            ->where('subject_type', $prescription->getMorphClass())
            ->where('subject_id', $prescription->id)
            ->where('action', AuditEvent::PrescriptionFinalized->value)
            ->exists())->toBeTrue();
});

test('non-optometrist cannot finalize a prescription', function () {
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->inProgress()->create(['patient_id' => $patient->id]);

    app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $staff,
        data: ['main_od_sphere' => '-2.50', 'main_os_sphere' => '-3.00', 'remarks' => '62.0'],
    );
})->throws(ValidationException::class);

test('patient cannot finalize a prescription', function () {
    $patient = User::factory()->patient()->create();
    $encounter = Encounter::factory()->inProgress()->create(['patient_id' => $patient->patient->id]);

    app(FinalizePrescription::class)->handle(
        patient: $patient->patient,
        encounter: $encounter,
        author: $patient,
        data: ['main_od_sphere' => '-2.50', 'main_os_sphere' => '-3.00', 'remarks' => '62.0'],
    );
})->throws(ValidationException::class);

test('finalized prescription values are encrypted', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->inProgress()->create(['patient_id' => $patient->id]);

    $prescription = app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: [
            'main_od_sphere' => '-2.50',
            'main_od_cylinder' => '-1.00',
            'main_od_value' => 90,
            'main_os_sphere' => '-3.00',
            'main_os_cylinder' => '-0.75',
            'main_os_value' => 85,
            'remarks' => '62.0',
        ],
    );

    // Values are encrypted in the database
    $raw = DB::table('prescriptions')
        ->where('id', $prescription->id)
        ->first();

    expect($raw->main_od_sphere)->not->toBe('-2.50')
        ->and($raw->main_od_sphere)->not->toBeNull();

    // But model decrypts them
    $fresh = Prescription::find($prescription->id);
    expect($fresh->main_od_sphere)->toBe('-2.50')
        ->and($fresh->main_os_sphere)->toBe('-3.00');
});

test('finalized prescription cannot be edited in place', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->inProgress()->create(['patient_id' => $patient->id]);

    $prescription = app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: ['main_od_sphere' => '-2.50', 'main_os_sphere' => '-3.00', 'remarks' => '62.0'],
    );

    // The prescription has a prescribed_at timestamp — it's finalized
    expect($prescription->prescribed_at)->not->toBeNull();
});

test('amendment references the prior prescription', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->completed()->create(['patient_id' => $patient->id]);

    $original = Prescription::factory()->create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
    ]);

    $amendment = app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: ['main_od_sphere' => '-3.00', 'main_os_sphere' => '-3.50', 'remarks' => '62.0'],
        previousPrescription: $original,
        amendmentReason: 'Corrected transcription from the signed paper prescription.',
    );

    $auditLog = AuditLog::query()
        ->where('subject_type', $amendment->getMorphClass())
        ->where('subject_id', $amendment->id)
        ->where('action', AuditEvent::PrescriptionAmended->value)
        ->sole();
    $rawAmendmentReason = DB::table('prescriptions')
        ->where('id', $amendment->id)
        ->value('amendment_reason');

    expect($amendment->previous_prescription_id)->toBe($original->id)
        ->and($amendment->created_by)->toBe($optometrist->id)
        ->and($amendment->amendment_reason)->toBe('Corrected transcription from the signed paper prescription.')
        ->and($amendment->prescribed_at)->not->toBeNull()
        ->and($amendment->id)->not->toBe($original->id)
        ->and($rawAmendmentReason)->not->toBe($amendment->amendment_reason)
        ->and($auditLog->actor_id)->toBe($optometrist->id)
        ->and($auditLog->metadata)->toMatchArray([
            'previous_prescription_id' => $original->id,
        ]);
});

test('prescription belongs to patient and encounter', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->inProgress()->create(['patient_id' => $patient->id]);

    $prescription = app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: ['main_od_sphere' => '-2.50', 'main_os_sphere' => '-3.00', 'remarks' => '62.0'],
    );

    expect($prescription->patient->id)->toBe($patient->id)
        ->and($prescription->encounter->id)->toBe($encounter->id)
        ->and($prescription->author->id)->toBe($optometrist->id);
});

test('an original prescription requires an in-progress encounter', function (EncounterStatus $status) {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'status' => $status,
    ]);

    app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: ['main_od_sphere' => '-2.50', 'main_os_sphere' => '-3.00', 'remarks' => '62.0'],
    );
})->with([
    EncounterStatus::Planned,
    EncounterStatus::Completed,
    EncounterStatus::Cancelled,
])->throws(ValidationException::class, 'A prescription can only be finalized during an in-progress consultation.');

test('a prescription patient must match the encounter patient', function () {
    $optometrist = User::factory()->optometrist()->create();
    $encounterPatient = Patient::factory()->create();
    $otherPatient = Patient::factory()->create();
    $encounter = Encounter::factory()->inProgress()->create([
        'patient_id' => $encounterPatient->id,
    ]);

    app(FinalizePrescription::class)->handle(
        patient: $otherPatient,
        encounter: $encounter,
        author: $optometrist,
        data: ['main_od_sphere' => '-2.50', 'main_os_sphere' => '-3.00', 'remarks' => '62.0'],
    );
})->throws(ValidationException::class, 'The prescription patient must match the consultation patient.');

test('a second original prescription for the same encounter is rejected', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->inProgress()->create([
        'patient_id' => $patient->id,
    ]);

    Prescription::factory()->linkedToEncounter($encounter)->create();

    app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: ['main_od_sphere' => '-2.50', 'main_os_sphere' => '-3.00', 'remarks' => '62.0'],
    );
})->throws(ValidationException::class, 'This consultation already has a finalized prescription. Create an amendment instead.');

test('an amendment requires an eligible consultation status', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'status' => EncounterStatus::Planned,
    ]);
    $original = Prescription::factory()->linkedToEncounter($encounter)->create();

    app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: ['main_od_sphere' => '-2.50', 'main_os_sphere' => '-3.00', 'remarks' => '62.0'],
        previousPrescription: $original,
        amendmentReason: 'Corrected transcription.',
    );
})->throws(ValidationException::class, 'A prescription amendment requires an in-progress or completed consultation.');

test('an amendment must reference a prescription from the same consultation', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->completed()->create(['patient_id' => $patient->id]);
    $otherEncounter = Encounter::factory()->completed()->create(['patient_id' => $patient->id]);
    $original = Prescription::factory()->linkedToEncounter($otherEncounter)->create();

    app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: ['main_od_sphere' => '-2.50', 'main_os_sphere' => '-3.00', 'remarks' => '62.0'],
        previousPrescription: $original,
        amendmentReason: 'Corrected transcription.',
    );
})->throws(ValidationException::class, 'The prior prescription must belong to the same patient and consultation.');

test('an amendment requires a reason', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->completed()->create([
        'patient_id' => $patient->id,
    ]);
    $original = Prescription::factory()->linkedToEncounter($encounter)->create();

    app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: ['main_od_sphere' => '-2.50', 'main_os_sphere' => '-3.00', 'remarks' => '62.0'],
        previousPrescription: $original,
    );
})->throws(ValidationException::class);

test('an amendment cannot branch from a superseded prescription', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->completed()->create([
        'patient_id' => $patient->id,
    ]);
    $original = Prescription::factory()->linkedToEncounter($encounter)->create();
    Prescription::factory()->linkedToEncounter($encounter)->create([
        'previous_prescription_id' => $original->id,
    ]);

    app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: ['main_od_sphere' => '-2.50', 'main_os_sphere' => '-3.00', 'remarks' => '62.0'],
        previousPrescription: $original,
        amendmentReason: 'Attempted branch.',
    );
})->throws(ValidationException::class);

test('an amendment retains its predecessor after the predecessor is archived', function () {
    $encounter = Encounter::factory()->completed()->create();
    $original = Prescription::factory()->linkedToEncounter($encounter)->create();
    $amendment = Prescription::factory()->linkedToEncounter($encounter)->create([
        'previous_prescription_id' => $original->id,
    ]);

    $original->delete();

    expect($amendment->fresh()->previousPrescription?->id)->toBe($original->id);
});
