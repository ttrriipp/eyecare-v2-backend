<?php

use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Quotation;
use App\Services\Eyewear\FindPatientEyewear;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('canonical key resolves to aggregate', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Presented,
        'eyewear_key' => 'eyw_CANONICALKEY123456789012345',
    ]);

    $result = app(FindPatientEyewear::class)->handle($patient, 'eyw_CANONICALKEY123456789012345');

    expect($result)->not->toBeNull()
        ->and($result->key)->toBe('eyw_CANONICALKEY123456789012345');
});

test('jo_ alias resolves to canonical aggregate', function () {
    $patient = Patient::factory()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'status' => JobOrderStatus::Queued,
    ]);

    $result = app(FindPatientEyewear::class)->handle($patient, "jo_{$jobOrder->id}");

    expect($result)->not->toBeNull()
        ->and($result->key)->toBe($jobOrder->eyewear_key);
});

test('alias never becomes the response key', function () {
    $patient = Patient::factory()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'status' => JobOrderStatus::Queued,
    ]);

    $result = app(FindPatientEyewear::class)->handle($patient, "jo_{$jobOrder->id}");

    expect($result->key)->not->toStartWith('jo_')
        ->and($result->key)->toStartWith('eyw_');
});

test('another patients canonical key returns null', function () {
    $patientA = Patient::factory()->create();
    $patientB = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patientB->id,
        'status' => QuotationStatus::Presented,
    ]);

    $result = app(FindPatientEyewear::class)->handle($patientA, $quotation->eyewear_key);

    expect($result)->toBeNull();
});

test('another patients jo_ alias returns null', function () {
    $patientA = Patient::factory()->create();
    $patientB = Patient::factory()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $patientB->id,
        'status' => JobOrderStatus::Queued,
    ]);

    $result = app(FindPatientEyewear::class)->handle($patientA, "jo_{$jobOrder->id}");

    expect($result)->toBeNull();
});

test('nonexistent key returns null', function () {
    $patient = Patient::factory()->create();

    $result = app(FindPatientEyewear::class)->handle($patient, 'eyw_NONEXISTENT1234567890123456');

    expect($result)->toBeNull();
});

test('nonexistent jo_ alias returns null', function () {
    $patient = Patient::factory()->create();

    $result = app(FindPatientEyewear::class)->handle($patient, 'jo_999999');

    expect($result)->toBeNull();
});

test('linked job order resolves via alias with quotation context', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Accepted,
        'total' => 5000,
    ]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'quotation_id' => $quotation->id,
        'status' => JobOrderStatus::InProgress,
        'eyewear_key' => $quotation->eyewear_key,
        'total_amount' => 5000,
    ]);

    $result = app(FindPatientEyewear::class)->handle($patient, "jo_{$jobOrder->id}");

    expect($result)->not->toBeNull()
        ->and($result->key)->toBe($quotation->eyewear_key)
        ->and($result->estimate)->not->toBeNull();
});

test('soft-deleted job order is not found', function () {
    $patient = Patient::factory()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'status' => JobOrderStatus::Cancelled,
    ]);
    $jobOrder->delete();

    $result = app(FindPatientEyewear::class)->handle($patient, "jo_{$jobOrder->id}");

    expect($result)->toBeNull();
});
