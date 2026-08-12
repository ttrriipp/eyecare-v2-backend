<?php

use App\Actions\OpticalOrders\CreateOpticalOrderFromQuotation;
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
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->actingAs($this->staff);
});

test('accepting a draft quotation creates a job order with snapshot items', function () {
    $quotation = Quotation::factory()->create([
        'subtotal' => 8500,
        'discount_amount' => 500,
        'total' => 8000,
    ]);

    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $lensCategory = LensCategory::factory()->create(['price' => 2000]);

    $quotation->items()->createMany([
        ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 4500, 'amount' => 4500, 'product_variant_id' => $variant->id],
        ['description' => 'Lens', 'quantity' => 2, 'unit_price' => 2000, 'amount' => 4000, 'lens_category_id' => $lensCategory->id],
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation, confirmer: $this->staff);

    expect($result['optical_order'])->toBeInstanceOf(JobOrder::class)
        ->and($result['optical_order']->quotation_id)->toBe($quotation->id)
        ->and($result['optical_order']->status)->toBe(JobOrderStatus::Queued)
        ->and((float) $result['optical_order']->total_amount)->toBe(8500.0);

    expect($result['optical_order']->items)->toHaveCount(2)
        ->and($result['optical_order']->items->first()->description)->toBe('Frame')
        ->and($result['optical_order']->items->last()->description)->toBe('Lens');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Accepted)
        ->and($quotation->fresh()->confirmed_by)->toBe($this->staff->id)
        ->and($quotation->fresh()->confirmed_at)->not->toBeNull();

    expect($result['billing_record'])->toBeInstanceOf(BillingRecord::class)
        ->and($result['billing_record']->status)->toBe(BillingRecordStatus::Unpaid)
        ->and((float) $result['billing_record']->total_amount)->toBe(8000.0)
        ->and((float) $result['billing_record']->discount_amount)->toBe(500.0)
        ->and((float) $result['billing_record']->subtotal_amount)->toBe(8500.0);
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

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation, confirmer: $this->staff);

    expect($result['optical_order']->quotation_id)->toBe($quotation->id)
        ->and($result['optical_order']->items)->toHaveCount(1)
        ->and($quotation->fresh()->status)->toBe(QuotationStatus::Accepted);
});

test('accepting is idempotent - returns existing records', function () {
    $quotation = Quotation::factory()->create([
        'subtotal' => 5000,
        'total' => 5000,
    ]);

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

test('heterogeneous items are all copied to job order', function () {
    $quotation = Quotation::factory()->create([
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

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation, confirmer: $this->staff);

    expect($result['optical_order']->items)->toHaveCount(4);

    $items = $result['optical_order']->items->sortBy('id')->values();
    expect($items[0]->product_variant_id)->toBe($variant->id)
        ->and($items[1]->lens_category_id)->toBe($lensCategory->id)
        ->and($items[2]->product_variant_id)->toBeNull()
        ->and($items[2]->lens_category_id)->toBeNull();
});

test('quotation confirmation creates job order', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $quotation = Quotation::factory()->create([
        'total' => 3000,
    ]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 3000,
        'amount' => 3000,
        'product_variant_id' => $variant->id,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation, confirmer: $this->staff);

    expect($result['optical_order'])->not->toBeNull();
});

test('declined quotation cannot be confirmed', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Declined]);

    app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation, confirmer: $this->staff);
})->throws(ValidationException::class);

test('payment due date is stored on billing record', function () {
    $quotation = Quotation::factory()->create(['total' => 5000]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $dueDate = Carbon::today()->addDays(30);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
        paymentDueDate: $dueDate,
    );

    expect($result['billing_record']->payment_due_date)->not->toBeNull()
        ->and($result['billing_record']->payment_due_date->format('Y-m-d'))->toBe($dueDate->format('Y-m-d'));
});

test('optional deposit is recorded as first payment', function () {
    $quotation = Quotation::factory()->create(['total' => 10000]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 10000,
        'amount' => 10000,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
        depositAmount: 3000,
        depositPaymentMethod: 'gcash',
        depositReference: 'GCASH-12345',
    );

    $billing = $result['billing_record']->fresh();

    expect((float) $billing->amount_paid)->toBe(3000.0)
        ->and((float) $billing->balance_due)->toBe(7000.0)
        ->and($billing->status)->toBe(BillingRecordStatus::PartiallyPaid)
        ->and($billing->payments)->toHaveCount(1)
        ->and($billing->payments->first()->payment_method)->toBe('gcash')
        ->and($billing->payments->first()->reference_number)->toBe('GCASH-12345');
});

test('full deposit results in paid status', function () {
    $quotation = Quotation::factory()->create(['total' => 5000]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
        depositAmount: 5000,
    );

    $billing = $result['billing_record']->fresh();

    expect((float) $billing->amount_paid)->toBe(5000.0)
        ->and((float) $billing->balance_due)->toBe(0.0)
        ->and($billing->status)->toBe(BillingRecordStatus::Paid);
});

test('zero deposit creates no payment', function () {
    $quotation = Quotation::factory()->create(['total' => 5000]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
        depositAmount: 0,
    );

    $billing = $result['billing_record']->fresh();

    expect((float) $billing->amount_paid)->toBe(0.0)
        ->and($billing->payments)->toHaveCount(0);
});
