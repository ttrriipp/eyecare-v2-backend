<?php

/**
 * Tests for prescription invariants on optical orders.
 */

use App\Actions\OpticalOrders\ValidateOpticalOrderItems;
use App\Enums\CommercialItemKind;
use App\Models\Patient;
use App\Models\Prescription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('corrective lens package requires a prescription', function () {
    $items = collect([
        ['item_kind' => CommercialItemKind::LensPackage, 'product_variant_id' => null],
    ]);

    app(ValidateOpticalOrderItems::class)->handle($items, patient: Patient::factory()->create());
})->throws(ValidationException::class, 'A current prescription is required');

test('corrective lens package requires patient-owned prescription', function () {
    $patient = Patient::factory()->create();
    $otherPatient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $otherPatient->id]);

    $items = collect([
        ['item_kind' => CommercialItemKind::LensPackage, 'product_variant_id' => null],
    ]);

    app(ValidateOpticalOrderItems::class)->handle($items, patient: $patient, prescription: $prescription);
})->throws(ValidationException::class, 'must belong to this patient');

test('corrective lens package requires current prescription version', function () {
    $patient = Patient::factory()->create();
    $original = Prescription::factory()->create(['patient_id' => $patient->id]);
    $superseded = Prescription::factory()->create([
        'patient_id' => $patient->id,
        'previous_prescription_id' => $original->id,
    ]);

    $items = collect([
        ['item_kind' => CommercialItemKind::LensPackage, 'product_variant_id' => null],
    ]);

    app(ValidateOpticalOrderItems::class)->handle($items, patient: $patient, prescription: $original);
})->throws(ValidationException::class, 'has been superseded');

test('corrective lens package rejects a voided prescription', function () {
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create([
        'patient_id' => $patient->id,
        'voided_at' => now(),
        'void_reason' => 'Wrong axis recorded',
    ]);

    $items = collect([
        ['item_kind' => CommercialItemKind::LensPackage, 'product_variant_id' => null],
    ]);

    app(ValidateOpticalOrderItems::class)->handle($items, patient: $patient, prescription: $prescription);
})->throws(ValidationException::class, 'has been voided');

test('corrective eyewear with valid patient prescription passes', function () {
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);

    $items = collect([
        ['item_kind' => CommercialItemKind::Frame, 'product_variant_id' => 1],
        ['item_kind' => CommercialItemKind::LensPackage, 'product_variant_id' => null],
    ]);

    $result = app(ValidateOpticalOrderItems::class)->handle(
        $items,
        patient: $patient,
        prescription: $prescription,
    );

    expect($result['is_corrective'])->toBeTrue();
});

test('non-corrective order does not require prescription', function () {
    $items = collect([
        ['item_kind' => CommercialItemKind::Frame, 'product_variant_id' => 1],
    ]);

    $result = app(ValidateOpticalOrderItems::class)->handle($items);

    expect($result['is_corrective'])->toBeFalse();
});

test('contact-lens-only order does not require spectacle prescription', function () {
    $items = collect([
        ['item_kind' => CommercialItemKind::ContactLens, 'product_variant_id' => 1],
    ]);

    $result = app(ValidateOpticalOrderItems::class)->handle($items);

    expect($result['is_corrective'])->toBeFalse();
});

test('spectacle prescription is not used as contact-lens authorization', function () {
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);

    $items = collect([
        ['item_kind' => CommercialItemKind::ContactLens, 'product_variant_id' => 1],
    ]);

    $result = app(ValidateOpticalOrderItems::class)->handle(
        $items,
        patient: $patient,
        prescription: null,
    );

    expect($result['is_corrective'])->toBeFalse();
});
