<?php

use App\Actions\JobOrders\CreateJobOrder;
use App\Enums\QuotationStatus;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('staff can create a job order from an accepted quotation', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->create(['patient_id' => $patient->id]);
    $prescription = Prescription::factory()->create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
    ]);

    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'prescription_id' => $prescription->id,
        'status' => QuotationStatus::Accepted,
    ]);

    $revision = QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'revision_number' => 1,
        'subtotal' => 5000,
        'total' => 5000,
    ]);

    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'description' => 'Classic Frame — Matte Black',
        'quantity' => 1,
        'unit_price' => 2500,
        'amount' => 2500,
    ]);

    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'description' => 'Progressive Lens',
        'quantity' => 1,
        'unit_price' => 2500,
        'amount' => 2500,
    ]);

    $jobOrder = app(CreateJobOrder::class)->handle($quotation, $staff);

    expect($jobOrder->patient_id)->toBe($patient->id)
        ->and($jobOrder->encounter_id)->toBe($encounter->id)
        ->and($jobOrder->prescription_id)->toBe($prescription->id)
        ->and($jobOrder->quotation_revision_id)->toBe($revision->id)
        ->and((float) $jobOrder->total_amount)->toBe(5000.0)
        ->and($jobOrder->items)->toHaveCount(2);
});

test('non-accepted quotation cannot create a job order', function () {
    $staff = User::factory()->staff()->create();
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);

    app(CreateJobOrder::class)->handle($quotation, $staff);
})->throws(ValidationException::class);

test('duplicate job order from same revision is prevented', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Accepted,
    ]);
    $revision = QuotationRevision::factory()->create(['quotation_id' => $quotation->id]);

    app(CreateJobOrder::class)->handle($quotation, $staff);

    // Second creation should fail
    app(CreateJobOrder::class)->handle($quotation, $staff);
})->throws(ValidationException::class);

test('job order requires finalized prescription for non-prescription orders', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'prescription_id' => null, // No prescription
        'status' => QuotationStatus::Accepted,
    ]);
    QuotationRevision::factory()->create(['quotation_id' => $quotation->id]);

    // Should still work — prescription is optional for non-prescription orders
    $jobOrder = app(CreateJobOrder::class)->handle($quotation, $staff);

    expect($jobOrder->prescription_id)->toBeNull();
});

test('job order snapshots quotation items', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Accepted,
    ]);
    $revision = QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'subtotal' => 3000,
        'total' => 3000,
    ]);
    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'description' => 'Frame A',
        'quantity' => 1,
        'unit_price' => 1500,
        'amount' => 1500,
    ]);
    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'description' => 'Lens B',
        'quantity' => 1,
        'unit_price' => 1500,
        'amount' => 1500,
    ]);

    $jobOrder = app(CreateJobOrder::class)->handle($quotation, $staff);

    expect($jobOrder->items)->toHaveCount(2)
        ->and($jobOrder->items->first()->description)->toBe('Frame A')
        ->and((float) $jobOrder->items->first()->unit_price)->toBe(1500.0);
});

test('job orders have no patient-facing creation route', function () {
    // Job orders are clinic-only — no POST to /job-orders or /job_orders
    // POST /job-order-items/{item}/rating is allowed (frame rating, not job order creation)
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => preg_match('#api/v1/job-orders?$#', $r->uri) || preg_match('#api/v1/job_orders?$#', $r->uri))
        ->filter(fn ($r) => in_array('POST', (array) $r->methods, true));

    expect($routes)->toBeEmpty();
});
