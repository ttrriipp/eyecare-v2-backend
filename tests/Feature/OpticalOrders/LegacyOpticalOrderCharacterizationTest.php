<?php

/**
 * Characterization tests for the legacy Quotation -> Revision -> Job Order -> Billing Record aggregate.
 *
 * These tests capture the invariants that the migration to direct relationships
 * must preserve: ownership, totals, line counts, stable eyewear_key, and the
 * single linked Job Order.
 */

use App\Actions\OpticalOrders\AcceptAndStartOpticalOrder;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\LensCategory;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationRevision;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->actingAs($this->staff);
});

test('quotation aggregate preserves ownership through the revision chain', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $quotation = Quotation::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'prescription_id' => $encounter->prescriptions()->first()->id,
        'status' => QuotationStatus::Draft,
    ]);

    $revision = QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'revision_number' => 1,
        'subtotal' => 10000,
        'discount_amount' => 500,
        'total' => 9500,
    ]);

    // Ownership chain: Quotation -> patient_id, encounter_id, prescription_id
    expect($quotation->patient_id)->toBe($encounter->patient_id)
        ->and($quotation->encounter_id)->toBe($encounter->id)
        ->and($quotation->prescription_id)->toBe($encounter->prescriptions()->first()->id);

    // Revision links back to quotation
    expect($revision->quotation_id)->toBe($quotation->id)
        ->and($quotation->revisions)->toHaveCount(1)
        ->and($quotation->latestRevision->id)->toBe($revision->id);
});

test('heterogeneous line items are preserved through revision', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $quotation = Quotation::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'status' => QuotationStatus::Draft,
    ]);

    $revision = QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'revision_number' => 1,
        'subtotal' => 12250,
        'discount_amount' => 0,
        'total' => 12250,
    ]);

    // Frame (product variant)
    $variant = ProductVariant::factory()->create();
    $revision->items()->create([
        'description' => 'Classic Rectangle Frame',
        'quantity' => 1,
        'unit_price' => 4500,
        'amount' => 4500,
        'product_variant_id' => $variant->id,
    ]);

    // Lens category
    $lensCategory = LensCategory::factory()->create(['price' => 3000]);
    $revision->items()->create([
        'description' => 'Single Vision Lens',
        'quantity' => 2,
        'unit_price' => 3000,
        'amount' => 6000,
        'lens_category_id' => $lensCategory->id,
    ]);

    // Service (no catalog reference)
    $revision->items()->create([
        'description' => 'Custom fitting service',
        'quantity' => 1,
        'unit_price' => 750,
        'amount' => 750,
    ]);

    // Custom charge line (no catalog reference)
    $revision->items()->create([
        'description' => 'Lens coating - anti-reflective',
        'quantity' => 1,
        'unit_price' => 1000,
        'amount' => 1000,
    ]);

    expect($revision->items)->toHaveCount(4)
        ->and($revision->items->where('product_variant_id', $variant->id))->toHaveCount(1)
        ->and($revision->items->where('lens_category_id', $lensCategory->id))->toHaveCount(1)
        ->and($revision->items->whereNull('product_variant_id')->whereNull('lens_category_id'))->toHaveCount(2);

    // Totals match sum of items
    expect((float) $revision->subtotal)->toBe(12250.0)
        ->and((float) $revision->total)->toBe(12250.0);
});

test('accepting creates one job order linked through quotation revision with matching totals', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $quotation = Quotation::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'prescription_id' => $encounter->prescriptions()->first()->id,
        'status' => QuotationStatus::Presented,
    ]);

    $revision = QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'revision_number' => 1,
        'subtotal' => 8000,
        'discount_amount' => 500,
        'total' => 7500,
    ]);

    $variant = ProductVariant::factory()->create();
    $revision->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 4500,
        'amount' => 4500,
        'product_variant_id' => $variant->id,
    ]);

    $lensCategory = LensCategory::factory()->create(['price' => 2000]);
    $revision->items()->create([
        'description' => 'Lens',
        'quantity' => 2,
        'unit_price' => 2000,
        'amount' => 4000,
        'lens_category_id' => $lensCategory->id,
    ]);

    $result = app(AcceptAndStartOpticalOrder::class)->handle($quotation);

    $jobOrder = $result['job_order'];

    // One Job Order linked through revision
    expect($jobOrder)->toBeInstanceOf(JobOrder::class)
        ->and($jobOrder->quotation_revision_id)->toBe($revision->id)
        ->and($jobOrder->patient_id)->toBe($quotation->patient_id)
        ->and($jobOrder->encounter_id)->toBe($quotation->encounter_id)
        ->and($jobOrder->prescription_id)->toBe($quotation->prescription_id);

    // Totals match
    expect((float) $jobOrder->total_amount)->toBe(7500.0);

    // Status is Queued
    expect($jobOrder->status)->toBe(JobOrderStatus::Queued);

    // Quotation is now Accepted
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Accepted);
});

test('job order snapshot preserves all line items from revision', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $quotation = Quotation::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'status' => QuotationStatus::Presented,
    ]);

    $revision = QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'revision_number' => 1,
        'subtotal' => 8250,
        'discount_amount' => 0,
        'total' => 8250,
    ]);

    $variant = ProductVariant::factory()->create();
    $lensCategory = LensCategory::factory()->create(['price' => 1500]);

    // Frame
    $revision->items()->create([
        'description' => 'Titanium Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
    ]);

    // Lens
    $revision->items()->create([
        'description' => 'Progressive Lens',
        'quantity' => 1,
        'unit_price' => 2500,
        'amount' => 2500,
        'lens_category_id' => $lensCategory->id,
    ]);

    // Service
    $revision->items()->create([
        'description' => 'Fitting and adjustment',
        'quantity' => 1,
        'unit_price' => 750,
        'amount' => 750,
    ]);

    $result = app(AcceptAndStartOpticalOrder::class)->handle($quotation);
    $jobOrder = $result['job_order'];

    // NOTE: Current AcceptAndStartOpticalOrder does NOT copy items to the Job Order.
    // Items remain on the QuotationRevision. The Job Order only stores the total.
    // This is a key invariant: the new direct workflow will need to copy items.
    expect($jobOrder->items)->toHaveCount(0)
        ->and((float) $jobOrder->total_amount)->toBe(8250.0);

    // Items are still on the revision
    expect($revision->fresh()->items)->toHaveCount(3);
});

test('billing record is created with matching totals at acceptance', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $quotation = Quotation::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'status' => QuotationStatus::Presented,
    ]);

    QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'revision_number' => 1,
        'subtotal' => 6000,
        'discount_amount' => 0,
        'total' => 6000,
    ]);

    $result = app(AcceptAndStartOpticalOrder::class)->handle($quotation);

    $billingRecord = $result['billing_record'];

    expect($billingRecord)->toBeInstanceOf(BillingRecord::class)
        ->and($billingRecord->job_order_id)->toBe($result['job_order']->id)
        ->and($billingRecord->patient_id)->toBe($quotation->patient_id)
        ->and((float) $billingRecord->total_amount)->toBe(6000.0)
        ->and((float) $billingRecord->amount_paid)->toBe(0.0)
        ->and((float) $billingRecord->balance_due)->toBe(6000.0)
        ->and($billingRecord->status)->toBe(BillingRecordStatus::Unpaid);
});

test('eyewear key is stable across quotation, job order, and billing', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $quotation = Quotation::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'status' => QuotationStatus::Presented,
        'eyewear_key' => 'eyw_01K1TESTKEY123456789',
    ]);

    QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'revision_number' => 1,
        'subtotal' => 3000,
        'discount_amount' => 0,
        'total' => 3000,
    ]);

    $result = app(AcceptAndStartOpticalOrder::class)->handle($quotation);

    // Same eyewear_key flows from Quotation -> Job Order
    expect($result['job_order']->eyewear_key)->toBe('eyw_01K1TESTKEY123456789')
        ->and($quotation->eyewear_key)->toBe('eyw_01K1TESTKEY123456789');
});

test('single linked job order per quotation is enforced', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $quotation = Quotation::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'status' => QuotationStatus::Presented,
    ]);

    QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'revision_number' => 1,
        'subtotal' => 5000,
        'discount_amount' => 0,
        'total' => 5000,
    ]);

    $first = app(AcceptAndStartOpticalOrder::class)->handle($quotation);
    $second = app(AcceptAndStartOpticalOrder::class)->handle($quotation->fresh());

    // Same Job Order returned, no duplicate
    expect($first['job_order']->id)->toBe($second['job_order']->id)
        ->and($first['billing_record']->id)->toBe($second['billing_record']->id);

    // Only one Job Order exists for this revision
    expect(JobOrder::where('quotation_revision_id', $quotation->latestRevision->id)->count())->toBe(1);
});

test('billing record links to the job order and patient', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $quotation = Quotation::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'status' => QuotationStatus::Presented,
    ]);

    QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'revision_number' => 1,
        'subtotal' => 4000,
        'discount_amount' => 0,
        'total' => 4000,
    ]);

    $result = app(AcceptAndStartOpticalOrder::class)->handle($quotation);

    // Billing -> Job Order link
    expect($result['billing_record']->jobOrder->id)->toBe($result['job_order']->id);

    // Job Order -> Billing Record link
    expect($result['job_order']->billingRecord->id)->toBe($result['billing_record']->id);

    // Both share patient
    expect($result['billing_record']->patient_id)->toBe($result['job_order']->patient_id)
        ->and($result['billing_record']->patient_id)->toBe($quotation->patient_id);
});

test('revision discount is reflected in accepted totals', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $quotation = Quotation::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'status' => QuotationStatus::Presented,
    ]);

    QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'revision_number' => 1,
        'subtotal' => 10000,
        'discount_amount' => 1500,
        'total' => 8500,
    ]);

    $result = app(AcceptAndStartOpticalOrder::class)->handle($quotation);

    // Job Order gets the discounted total
    expect((float) $result['job_order']->total_amount)->toBe(8500.0);

    // Billing Record gets the same discounted total
    expect((float) $result['billing_record']->total_amount)->toBe(8500.0)
        ->and((float) $result['billing_record']->balance_due)->toBe(8500.0);
});
