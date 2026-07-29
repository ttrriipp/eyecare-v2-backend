<?php

use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Quotation;
use App\Models\QuotationRevision;
use App\Services\Eyewear\ListPatientEyewear;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('estimate-only record appears in list', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Presented,
    ]);
    QuotationRevision::factory()->create(['quotation_id' => $quotation->id]);

    $paginator = app(ListPatientEyewear::class)->handle($patient, 'current', 15);

    expect($paginator->total())->toBe(1)
        ->and($paginator->items()[0]->key)->toBe($quotation->eyewear_key);
});

test('job-order-only record appears in list', function () {
    $patient = Patient::factory()->create();
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'status' => JobOrderStatus::Queued,
    ]);

    $paginator = app(ListPatientEyewear::class)->handle($patient, 'current', 15);

    expect($paginator->total())->toBe(1)
        ->and($paginator->items()[0]->key)->toBe($jobOrder->eyewear_key);
});

test('linked quotation and job order produce one list entry', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Accepted,
        'eyewear_key' => 'eyw_DUPLICATEKEY123456789012345',
    ]);
    $revision = QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
    ]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'quotation_revision_id' => $revision->id,
        'status' => JobOrderStatus::InProgress,
        'eyewear_key' => 'eyw_DUPLICATEKEY123456789012345',
    ]);

    $paginator = app(ListPatientEyewear::class)->handle($patient, 'current', 15);

    expect($paginator->total())->toBe(1);
});

test('draft estimate is excluded', function () {
    $patient = Patient::factory()->create();
    Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Draft,
    ]);

    $paginator = app(ListPatientEyewear::class)->handle($patient, 'current', 15);

    expect($paginator->total())->toBe(0);
});

test('other patients records are excluded', function () {
    $patientA = Patient::factory()->create();
    $patientB = Patient::factory()->create();

    Quotation::factory()->create([
        'patient_id' => $patientA->id,
        'status' => QuotationStatus::Presented,
    ]);
    Quotation::factory()->create([
        'patient_id' => $patientB->id,
        'status' => QuotationStatus::Presented,
    ]);

    $paginator = app(ListPatientEyewear::class)->handle($patientA, 'current', 15);

    expect($paginator->total())->toBe(1);
});

test('current filter excludes dispensed and cancelled', function () {
    $patient = Patient::factory()->create();
    JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'status' => JobOrderStatus::Dispensed,
    ]);
    JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'status' => JobOrderStatus::Cancelled,
    ]);

    $paginator = app(ListPatientEyewear::class)->handle($patient, 'current', 15);

    expect($paginator->total())->toBe(0);
});

test('history filter includes dispensed and cancelled', function () {
    $patient = Patient::factory()->create();
    JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'status' => JobOrderStatus::Dispensed,
    ]);
    JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'status' => JobOrderStatus::Cancelled,
    ]);

    $paginator = app(ListPatientEyewear::class)->handle($patient, 'history', 15);

    expect($paginator->total())->toBe(2);
});

test('default filter is current when omitted', function () {
    $patient = Patient::factory()->create();
    JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'status' => JobOrderStatus::Queued,
    ]);

    $paginator = app(ListPatientEyewear::class)->handle($patient);

    expect($paginator->total())->toBe(1);
});

test('pagination respects per_page', function () {
    $patient = Patient::factory()->create();
    for ($i = 0; $i < 5; $i++) {
        JobOrder::factory()->create([
            'patient_id' => $patient->id,
            'status' => JobOrderStatus::Queued,
        ]);
    }

    $paginator = app(ListPatientEyewear::class)->handle($patient, 'current', 2);

    expect($paginator->perPage())->toBe(2)
        ->and($paginator->total())->toBe(5)
        ->and($paginator->lastPage())->toBe(3);
});

test('ordering is by activity_at descending then key ascending', function () {
    $patient = Patient::factory()->create();
    $j1 = JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'status' => JobOrderStatus::Queued,
        'created_at' => now()->subDays(2),
        'eyewear_key' => 'eyw_KEY_AAAAAAAAAAAAAAAAAAAAAAAAA',
    ]);
    $j2 = JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'status' => JobOrderStatus::InProgress,
        'started_at' => now(),
        'created_at' => now()->subDays(1),
        'eyewear_key' => 'eyw_KEY_BBBBBBBBBBBBBBBBBBBBBBBBB',
    ]);

    $paginator = app(ListPatientEyewear::class)->handle($patient, 'current', 15);
    $items = $paginator->items();

    // Most recent activity should be first
    expect($items[0]->key)->toBe($j2->eyewear_key)
        ->and($items[1]->key)->toBe($j1->eyewear_key);
});
