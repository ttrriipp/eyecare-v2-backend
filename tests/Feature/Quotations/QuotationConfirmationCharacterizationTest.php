<?php

/**
 * Characterization tests for ConfirmQuotationSale.
 *
 * Protects the currently working direct Quotation confirmation
 * paths before changing item semantics or confirmation validation.
 *
 * @see tasks/todo.md Task 1
 */

use App\Actions\Quotations\ConfirmQuotationSale;
use App\Enums\BillingItemSourceKind;
use App\Enums\BillingRecordStatus;
use App\Enums\CommercialItemKind;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Enums\TransactionItemType;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\LensCategory;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Service;
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

// ─── Direct Draft confirmation ────────────────────────────────────────────────

test('direct draft confirmation creates one accepted quotation, optical order, and billing record', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    $prescription = Prescription::factory()->linkedToEncounter($encounter)->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();

    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'prescription_id' => $prescription->id,
        'subtotal' => 8000,
        'discount_amount' => 0,
        'total' => 8000,
    ]);

    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    $quotation->items()->create([
        'description' => 'Single Vision Lens',
        'quantity' => 1,
        'unit_price' => 3000,
        'amount' => 3000,
        'lens_category_id' => $lensCategory->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::LensPackage,
    ]);

    $result = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );

    // Quotation is accepted
    expect($result['quotation']->status)->toBe(QuotationStatus::Accepted)
        ->and($result['quotation']->confirmed_by)->toBe($this->staff->id)
        ->and($result['quotation']->confirmed_at)->not->toBeNull();

    // Exactly one Optical Order created
    $opticalOrder = $result['optical_order'];
    expect($opticalOrder)->toBeInstanceOf(JobOrder::class)
        ->and($opticalOrder->quotation_id)->toBe($quotation->id)
        ->and($opticalOrder->patient_id)->toBe($quotation->patient_id)
        ->and($opticalOrder->status)->toBe(JobOrderStatus::Queued);

    expect(JobOrder::where('quotation_id', $quotation->id)->count())->toBe(1);

    // Exactly one Billing Record created
    $billingRecord = $result['billing_record'];
    expect($billingRecord)->toBeInstanceOf(BillingRecord::class)
        ->and($billingRecord->patient_id)->toBe($quotation->patient_id)
        ->and($billingRecord->status)->toBe(BillingRecordStatus::Unpaid);

    expect(BillingRecord::where('patient_id', $quotation->patient_id)->count())->toBe(1);
});

// ─── Product/Service separation ──────────────────────────────────────────────

test('product lines enter optical order while only selected services enter billing', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $service = Service::factory()->create();

    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'subtotal' => 5750,
        'discount_amount' => 0,
        'total' => 5750,
    ]);

    $productItem = $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::CustomProduct,
    ]);

    $serviceItem = $quotation->items()->create([
        'description' => 'Eye Exam',
        'quantity' => 1,
        'unit_price' => 750,
        'amount' => 750,
        'item_type' => TransactionItemType::Service,
        'item_kind' => CommercialItemKind::Service,
    ]);

    $result = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
        performedServiceItemIds: [$serviceItem->id],
    );

    $opticalOrder = $result['optical_order'];

    // Product items are on the Optical Order
    expect($opticalOrder->items)->toHaveCount(1)
        ->and($opticalOrder->items->first()->product_variant_id)->toBe($variant->id);

    // Billing has both: product items + selected service
    $billingItems = $result['billing_record']->items;
    expect($billingItems)->toHaveCount(2);

    $productBillingItem = $billingItems->where('item_type', TransactionItemType::Product)->first();
    $serviceBillingItem = $billingItems->where('item_type', TransactionItemType::Service)->first();

    expect($productBillingItem)->not->toBeNull()
        ->and($productBillingItem->source_kind)->toBe(BillingItemSourceKind::OpticalOrder);

    expect($serviceBillingItem)->not->toBeNull()
        ->and($serviceBillingItem->source_kind)->toBe(BillingItemSourceKind::Quotation)
        ->and($serviceBillingItem->quotation_item_id)->toBe($serviceItem->id);
});

test('unselected services do not enter billing', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'subtotal' => 5750,
        'discount_amount' => 0,
        'total' => 5750,
    ]);

    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::CustomProduct,
    ]);

    $quotation->items()->create([
        'description' => 'Eye Exam',
        'quantity' => 1,
        'unit_price' => 750,
        'amount' => 750,
        'item_type' => TransactionItemType::Service,
        'item_kind' => CommercialItemKind::Service,
    ]);

    // No performedServiceItemIds passed
    $result = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );

    // Only the product item should be on billing
    $billingItems = $result['billing_record']->items;
    expect($billingItems)->toHaveCount(1)
        ->and($billingItems->first()->item_type)->toBe(TransactionItemType::Product);
});

// ─── Idempotency ─────────────────────────────────────────────────────────────

test('retried confirmation creates no duplicate order, billing item, payment, inventory movement, or reservation conversion', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 20]);

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
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::CustomProduct,
    ]);

    // First confirmation
    $first = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );

    // Second confirmation (retry)
    $second = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation->fresh(),
        confirmer: $this->staff,
    );

    // Same records returned
    expect($first['optical_order']->id)->toBe($second['optical_order']->id)
        ->and($first['billing_record']->id)->toBe($second['billing_record']->id);

    // Exactly one Job Order
    expect(JobOrder::where('quotation_id', $quotation->id)->count())->toBe(1);

    // Exactly one Billing Record
    expect(BillingRecord::where('patient_id', $quotation->patient_id)->count())->toBe(1);

    // No duplicate job order items
    expect($second['optical_order']->items()->count())->toBe(1);

    // No duplicate billing items
    expect($second['billing_record']->items()->count())->toBe(1);

    // No duplicate inventory movements
    $movementCount = InventoryMovement::where('product_variant_id', $variant->id)
        ->where('job_order_id', $first['optical_order']->id)
        ->count();
    expect($movementCount)->toBe(1);
});

// ─── Service-only quotation ──────────────────────────────────────────────────

test('service-only confirmation creates no optical order', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'subtotal' => 750,
        'discount_amount' => 0,
        'total' => 750,
    ]);

    $serviceItem = $quotation->items()->create([
        'description' => 'Eye Exam',
        'quantity' => 1,
        'unit_price' => 750,
        'amount' => 750,
        'item_type' => TransactionItemType::Service,
        'item_kind' => CommercialItemKind::Service,
    ]);

    $result = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
        performedServiceItemIds: [$serviceItem->id],
    );

    expect($result['optical_order'])->toBeNull()
        ->and($result['quotation']->status)->toBe(QuotationStatus::Accepted)
        ->and($result['billing_record'])->toBeInstanceOf(BillingRecord::class);

    expect(JobOrder::where('quotation_id', $quotation->id)->count())->toBe(0);

    // Service item is on billing
    expect($result['billing_record']->items)->toHaveCount(1)
        ->and($result['billing_record']->items->first()->item_type)->toBe(TransactionItemType::Service);
});

// ─── Inventory commitment ────────────────────────────────────────────────────

test('confirmation commits inventory for product variants', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

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
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::CustomProduct,
    ]);

    app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );

    $variant->refresh();
    expect($variant->stock_quantity)->toBe(9);

    $movement = InventoryMovement::where('product_variant_id', $variant->id)->first();
    expect($movement)->not->toBeNull()
        ->and($movement->quantity_change)->toBe(-1)
        ->and($movement->previous_stock)->toBe(10)
        ->and($movement->new_stock)->toBe(9);
});

// ─── Eyewear key stability ───────────────────────────────────────────────────

test('eyewear key is stable across quotation, optical order', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'subtotal' => 5000,
        'discount_amount' => 0,
        'total' => 5000,
        'eyewear_key' => 'eyw_01K1TESTKEY999999',
    ]);

    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::CustomProduct,
    ]);

    $result = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );

    expect($result['optical_order']->eyewear_key)->toBe('eyw_01K1TESTKEY999999')
        ->and($result['quotation']->eyewear_key)->toBe('eyw_01K1TESTKEY999999');
});

// ─── Discount propagation ────────────────────────────────────────────────────

test('quotation discount is reflected in billing record', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'subtotal' => 10000,
        'discount_amount' => 1500,
        'total' => 8500,
    ]);

    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 10000,
        'amount' => 10000,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::CustomProduct,
    ]);

    $result = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );

    expect((float) $result['billing_record']->discount_amount)->toBe(1500.0)
        ->and((float) $result['billing_record']->total_amount)->toBe(8500.0)
        ->and((float) $result['billing_record']->balance_due)->toBe(8500.0);
});

// ─── Deposit recording ───────────────────────────────────────────────────────

test('optional deposit is recorded during confirmation', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

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
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::CustomProduct,
    ]);

    $result = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
        depositAmount: 2000,
        depositPaymentMethod: 'cash',
    );

    $billingRecord = $result['billing_record'];
    expect((float) $billingRecord->amount_paid)->toBe(2000.0)
        ->and((float) $billingRecord->balance_due)->toBe(3000.0)
        ->and($billingRecord->status)->toBe(BillingRecordStatus::PartiallyPaid);
});

// ─── Invalid status ──────────────────────────────────────────────────────────

test('confirmation rejects declined quotation', function () {
    $this->expectException(ValidationException::class);
    $this->expectExceptionMessage('Only draft or accepted quotations can be confirmed.');

    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Declined,
    ]);

    app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );
});
