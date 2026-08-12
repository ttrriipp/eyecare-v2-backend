<?php

/**
 * Characterization tests for the optical order aggregate.
 *
 * These tests capture the invariants of the direct Quotation -> Job Order -> Billing Record
 * aggregate after the migration to direct relationships.
 */

use App\Actions\OpticalOrders\CreateOpticalOrderFromQuotation;
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
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->actingAs($this->staff);
});

test('quotation aggregate preserves ownership', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $quotation = Quotation::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'prescription_id' => $encounter->prescriptions()->first()->id,
        'status' => QuotationStatus::Draft,
    ]);

    expect($quotation->patient_id)->toBe($encounter->patient_id)
        ->and($quotation->encounter_id)->toBe($encounter->id)
        ->and($quotation->prescription_id)->toBe($encounter->prescriptions()->first()->id);
});

test('heterogeneous line items are preserved on quotation', function () {
    $quotation = Quotation::factory()->create([
        'subtotal' => 12250,
        'total' => 12250,
    ]);

    $variant = ProductVariant::factory()->create();
    $lensCategory = LensCategory::factory()->create(['price' => 3000]);

    $quotation->items()->createMany([
        ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 4500, 'amount' => 4500, 'product_variant_id' => $variant->id],
        ['description' => 'Lens', 'quantity' => 2, 'unit_price' => 3000, 'amount' => 6000, 'lens_category_id' => $lensCategory->id],
        ['description' => 'Service', 'quantity' => 1, 'unit_price' => 750, 'amount' => 750],
        ['description' => 'Coating', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000],
    ]);

    expect($quotation->items)->toHaveCount(4)
        ->and($quotation->items->whereNotNull('product_variant_id'))->toHaveCount(1)
        ->and($quotation->items->whereNotNull('lens_category_id'))->toHaveCount(1)
        ->and($quotation->items->whereNull('product_variant_id')->whereNull('lens_category_id'))->toHaveCount(2);
});

test('accepting creates one job order linked via direct quotation_id', function () {
    $quotation = Quotation::factory()->create([
        'subtotal' => 8000,
        'discount_amount' => 500,
        'total' => 7500,
    ]);

    $variant = ProductVariant::factory()->create(['stock_quantity' => 1]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 4500,
        'amount' => 4500,
        'product_variant_id' => $variant->id,
    ]);

    $lensCategory = LensCategory::factory()->create(['price' => 2000]);
    $quotation->items()->create([
        'description' => 'Lens',
        'quantity' => 2,
        'unit_price' => 2000,
        'amount' => 4000,
        'lens_category_id' => $lensCategory->id,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation, confirmer: $this->staff);
    $jobOrder = $result['optical_order'];

    expect($jobOrder)->toBeInstanceOf(JobOrder::class)
        ->and($jobOrder->quotation_id)->toBe($quotation->id)
        ->and($jobOrder->patient_id)->toBe($quotation->patient_id)
        ->and($jobOrder->encounter_id)->toBe($quotation->encounter_id)
        ->and($jobOrder->prescription_id)->toBe($quotation->prescription_id)
        ->and((float) $jobOrder->total_amount)->toBe(8500.0)
        ->and($jobOrder->status)->toBe(JobOrderStatus::Queued)
        ->and($quotation->fresh()->status)->toBe(QuotationStatus::Accepted);
});

test('job order snapshot preserves all line items from quotation', function () {
    $quotation = Quotation::factory()->create([
        'subtotal' => 8250,
        'total' => 8250,
    ]);

    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $lensCategory = LensCategory::factory()->create(['price' => 1500]);

    $quotation->items()->createMany([
        ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 5000, 'amount' => 5000, 'product_variant_id' => $variant->id],
        ['description' => 'Lens', 'quantity' => 1, 'unit_price' => 2500, 'amount' => 2500, 'lens_category_id' => $lensCategory->id],
        ['description' => 'Service', 'quantity' => 1, 'unit_price' => 750, 'amount' => 750],
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation, confirmer: $this->staff);
    $jobOrder = $result['optical_order'];

    expect($jobOrder->items)->toHaveCount(3)
        ->and((float) $jobOrder->total_amount)->toBe(8250.0);
});

test('billing record is created with matching totals at acceptance', function () {
    $quotation = Quotation::factory()->create(['total' => 6000]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 6000,
        'amount' => 6000,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation, confirmer: $this->staff);

    $billingRecord = $result['billing_record'];

    expect($billingRecord)->toBeInstanceOf(BillingRecord::class)
        ->and($billingRecord->job_order_id)->toBe($result['optical_order']->id)
        ->and($billingRecord->patient_id)->toBe($quotation->patient_id)
        ->and((float) $billingRecord->total_amount)->toBe(6000.0)
        ->and((float) $billingRecord->amount_paid)->toBe(0.0)
        ->and((float) $billingRecord->balance_due)->toBe(6000.0)
        ->and($billingRecord->status)->toBe(BillingRecordStatus::Unpaid);
});

test('eyewear key is stable across quotation, job order, and billing', function () {
    $quotation = Quotation::factory()->create([
        'eyewear_key' => 'eyw_01K1TESTKEY123456789',
        'total' => 3000,
    ]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 3000,
        'amount' => 3000,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation, confirmer: $this->staff);

    expect($result['optical_order']->eyewear_key)->toBe('eyw_01K1TESTKEY123456789')
        ->and($quotation->eyewear_key)->toBe('eyw_01K1TESTKEY123456789');
});

test('single linked job order per quotation is enforced', function () {
    $quotation = Quotation::factory()->create(['total' => 5000]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $first = app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation, confirmer: $this->staff);
    $second = app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation->fresh(), confirmer: $this->staff);

    expect($first['optical_order']->id)->toBe($second['optical_order']->id)
        ->and($first['billing_record']->id)->toBe($second['billing_record']->id);

    expect(JobOrder::where('quotation_id', $quotation->id)->count())->toBe(1);
});

test('billing record links to the job order and patient', function () {
    $quotation = Quotation::factory()->create(['total' => 4000]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 4000,
        'amount' => 4000,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation, confirmer: $this->staff);

    expect($result['billing_record']->jobOrder->id)->toBe($result['optical_order']->id)
        ->and($result['optical_order']->billingRecord->id)->toBe($result['billing_record']->id)
        ->and($result['billing_record']->patient_id)->toBe($result['optical_order']->patient_id)
        ->and($result['billing_record']->patient_id)->toBe($quotation->patient_id);
});

test('quotation discount is reflected in accepted totals', function () {
    $quotation = Quotation::factory()->create([
        'subtotal' => 10000,
        'discount_amount' => 1500,
        'total' => 8500,
    ]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 10000,
        'amount' => 10000,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation, confirmer: $this->staff);

    expect((float) $result['optical_order']->total_amount)->toBe(10000.0)
        ->and((float) $result['billing_record']->total_amount)->toBe(8500.0)
        ->and((float) $result['billing_record']->balance_due)->toBe(8500.0);
});
