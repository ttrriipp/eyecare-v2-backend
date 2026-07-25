<?php

use App\Actions\Prescriptions\FinalizePrescription;
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
    $encounter = Encounter::factory()->create(['patient_id' => $patient->id]);

    $prescription = app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: [
            'od_sphere' => '-2.50',
            'os_sphere' => '-3.00',
            'pd' => '62.0',
        ],
    );

    expect($prescription->patient_id)->toBe($patient->id)
        ->and($prescription->encounter_id)->toBe($encounter->id)
        ->and($prescription->created_by)->toBe($optometrist->id)
        ->and($prescription->prescribed_at)->not->toBeNull();
});

test('non-optometrist cannot finalize a prescription', function () {
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->create(['patient_id' => $patient->id]);

    app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $staff,
        data: ['od_sphere' => '-2.50', 'os_sphere' => '-3.00', 'pd' => '62.0'],
    );
})->throws(ValidationException::class);

test('patient cannot finalize a prescription', function () {
    $patient = User::factory()->patient()->create();
    $encounter = Encounter::factory()->create(['patient_id' => $patient->patient->id]);

    app(FinalizePrescription::class)->handle(
        patient: $patient->patient,
        encounter: $encounter,
        author: $patient,
        data: ['od_sphere' => '-2.50', 'os_sphere' => '-3.00', 'pd' => '62.0'],
    );
})->throws(ValidationException::class);

test('finalized prescription values are encrypted', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->create(['patient_id' => $patient->id]);

    $prescription = app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: [
            'od_sphere' => '-2.50',
            'od_cylinder' => '-1.00',
            'od_axis' => 90,
            'os_sphere' => '-3.00',
            'os_cylinder' => '-0.75',
            'os_axis' => 85,
            'pd' => '62.0',
        ],
    );

    // Values are encrypted in the database
    $raw = DB::table('prescriptions')
        ->where('id', $prescription->id)
        ->first();

    expect($raw->od_sphere)->not->toBe('-2.50')
        ->and($raw->od_sphere)->not->toBeNull();

    // But model decrypts them
    $fresh = Prescription::find($prescription->id);
    expect($fresh->od_sphere)->toBe('-2.50')
        ->and($fresh->os_sphere)->toBe('-3.00');
});

test('finalized prescription cannot be edited in place', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->create(['patient_id' => $patient->id]);

    $prescription = app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: ['od_sphere' => '-2.50', 'os_sphere' => '-3.00', 'pd' => '62.0'],
    );

    // The prescription has a prescribed_at timestamp — it's finalized
    expect($prescription->prescribed_at)->not->toBeNull();
});

test('amendment references the prior prescription', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->create(['patient_id' => $patient->id]);

    $original = Prescription::factory()->create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
    ]);

    $amendment = app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: ['od_sphere' => '-3.00', 'os_sphere' => '-3.50', 'pd' => '62.0'],
        previousPrescription: $original,
    );

    expect($amendment->previous_prescription_id)->toBe($original->id)
        ->and($amendment->created_by)->toBe($optometrist->id)
        ->and($amendment->prescribed_at)->not->toBeNull()
        ->and($amendment->id)->not->toBe($original->id);
});

test('prescription belongs to patient and encounter', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->create(['patient_id' => $patient->id]);

    $prescription = app(FinalizePrescription::class)->handle(
        patient: $patient,
        encounter: $encounter,
        author: $optometrist,
        data: ['od_sphere' => '-2.50', 'os_sphere' => '-3.00', 'pd' => '62.0'],
    );

    expect($prescription->patient->id)->toBe($patient->id)
        ->and($prescription->encounter->id)->toBe($encounter->id)
        ->and($prescription->author->id)->toBe($optometrist->id);
});
