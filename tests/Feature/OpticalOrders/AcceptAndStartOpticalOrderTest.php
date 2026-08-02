<?php

use App\Actions\OpticalOrders\AcceptAndStartOpticalOrder;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\LensCategory;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->actingAs($this->staff);
});

test('accepting a presented quotation creates a job order with snapshot items', function () {
    $quotation = Quotation::factory()->presented()->create([
        'subtotal' => 8000,
        'discount_amount' => 500,
        'total' => 7500,
    ]);

    $variant = ProductVariant::factory()->create();
    $lensCategory = LensCategory::factory()->create(['price' => 2000]);

    // Direct items on quotation
    $quotation->items()->createMany([
        ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 4500, 'amount' => 4500, 'product_variant_id' => $variant->id],
        ['description' => 'Lens', 'quantity' => 2, 'unit_price' => 2000, 'amount' => 4000, 'lens_category_id' => $lensCategory->id],
    ]);

    $result = app(AcceptAndStartOpticalOrder::class)->handle($quotation);

    // Job Order created with direct quotation_id
    expect($result['job_order'])->toBeInstanceOf(JobOrder::class)
        ->and($result['job_order']->quotation_id)->toBe($quotation->id)
        ->and($result['job_order']->status)->toBe(JobOrderStatus::Queued)
        ->and((float) $result['job_order']->total_amount)->toBe(7500.0);

    // Items are copied from quotation to job order
    expect($result['job_order']->items)->toHaveCount(2)
        ->and($result['job_order']->items->first()->description)->toBe('Frame')
        ->and($result['job_order']->items->last()->description)->toBe('Lens');

    // Quotation is accepted with confirmation metadata
    expect($quotation->fresh()->status)->toBe(QuotationStatus::Accepted)
        ->and($quotation->fresh()->confirmed_by)->toBe($this->staff->id)
        ->and($quotation->fresh()->confirmed_at)->not->toBeNull();

    // Billing Record created
    expect($result['billing_record'])->toBeInstanceOf(BillingRecord::class)
        ->and($result['billing_record']->status)->toBe(BillingRecordStatus::Unpaid)
        ->and((float) $result['billing_record']->total_amount)->toBe(7500.0);
});

test('accepting a draft quotation (direct sale) creates job order and billing', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'subtotal' => 5000,
        'discount_amount' => 0,
        'total' => 5000,
    ]);

    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $result = app(AcceptAndStartOpticalOrder::class)->handle($quotation);

    expect($result['job_order']->quotation_id)->toBe($quotation->id)
        ->and($result['job_order']->items)->toHaveCount(1)
        ->and($quotation->fresh()->status)->toBe(QuotationStatus::Accepted);
});

test('accepting is idempotent - returns existing records', function () {
    $quotation = Quotation::factory()->presented()->create([
        'subtotal' => 5000,
        'total' => 5000,
    ]);

    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $first = app(AcceptAndStartOpticalOrder::class)->handle($quotation);
    $second = app(AcceptAndStartOpticalOrder::class)->handle($quotation->fresh());

    expect($first['job_order']->id)->toBe($second['job_order']->id)
        ->and($first['billing_record']->id)->toBe($second['billing_record']->id);

    // Only one job order exists
    expect(JobOrder::where('quotation_id', $quotation->id)->count())->toBe(1);
});

test('heterogeneous items are all copied to job order', function () {
    $quotation = Quotation::factory()->presented()->create([
        'subtotal' => 12250,
        'total' => 12250,
    ]);

    $variant = ProductVariant::factory()->create();
    $lensCategory = LensCategory::factory()->create(['price' => 3000]);

    $quotation->items()->createMany([
        ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 4500, 'amount' => 4500, 'product_variant_id' => $variant->id],
        ['description' => 'Lens', 'quantity' => 2, 'unit_price' => 3000, 'amount' => 6000, 'lens_category_id' => $lensCategory->id],
        ['description' => 'Fitting service', 'quantity' => 1, 'unit_price' => 750, 'amount' => 750],
        ['description' => 'Coating', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000],
    ]);

    $result = app(AcceptAndStartOpticalOrder::class)->handle($quotation);

    expect($result['job_order']->items)->toHaveCount(4);

    // Verify each item type
    $items = $result['job_order']->items->sortBy('id')->values();
    expect($items[0]->product_variant_id)->toBe($variant->id)
        ->and($items[1]->lens_category_id)->toBe($lensCategory->id)
        ->and($items[2]->product_variant_id)->toBeNull()
        ->and($items[2]->lens_category_id)->toBeNull();
});

test('eyewear key is preserved across quotation and job order', function () {
    $quotation = Quotation::factory()->presented()->create([
        'eyewear_key' => 'eyw_01TESTKEY999',
        'total' => 3000,
    ]);

    $result = app(AcceptAndStartOpticalOrder::class)->handle($quotation);

    expect($result['job_order']->eyewear_key)->toBe('eyw_01TESTKEY999');
});

test('declined quotation cannot be confirmed', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Declined]);

    app(AcceptAndStartOpticalOrder::class)->handle($quotation);
})->throws(ValidationException::class);

test('expired quotation cannot be confirmed', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Expired]);

    app(AcceptAndStartOpticalOrder::class)->handle($quotation);
})->throws(ValidationException::class);
