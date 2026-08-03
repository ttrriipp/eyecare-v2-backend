<?php

use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Quotation;
use App\Services\Eyewear\ListPatientEyewear;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('estimate only record appears in list', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Presented,
        'total' => 5000,
    ]);

    $result = app(ListPatientEyewear::class)->handle($patient);

    expect($result->total())->toBe(1);
});

test('job order only record appears in list', function () {
    $patient = Patient::factory()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'status' => JobOrderStatus::Queued,
    ]);

    $result = app(ListPatientEyewear::class)->handle($patient);

    expect($result->total())->toBe(1);
});

test('linked quotation and job order produce one list entry', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Accepted,
        'total' => 5000,
    ]);

    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'quotation_id' => $quotation->id,
        'eyewear_key' => $quotation->eyewear_key,
        'status' => JobOrderStatus::Queued,
    ]);

    $result = app(ListPatientEyewear::class)->handle($patient);

    expect($result->total())->toBe(1);
});

test('draft quotation excluded from list', function () {
    $patient = Patient::factory()->create();
    Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Draft,
    ]);

    $result = app(ListPatientEyewear::class)->handle($patient);

    expect($result->total())->toBe(0);
});

test('current filter includes estimate and in-progress', function () {
    $patient = Patient::factory()->create();

    // Estimate
    Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Presented,
    ]);

    // In progress
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Accepted,
    ]);
    JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'quotation_id' => $quotation->id,
        'eyewear_key' => $quotation->eyewear_key,
        'status' => JobOrderStatus::InProgress,
    ]);

    $result = app(ListPatientEyewear::class)->handle($patient, 'current');

    expect($result->total())->toBe(2);
});
