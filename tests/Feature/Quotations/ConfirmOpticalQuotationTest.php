<?php

/**
 * Tests for optical validation inside ConfirmQuotationSale.
 *
 * @see tasks/todo.md Task 14
 */

use App\Actions\Quotations\ConfirmQuotationSale;
use App\Enums\CommercialItemKind;
use App\Enums\QuotationStatus;
use App\Enums\TransactionItemType;
use App\Models\Encounter;
use App\Models\LensCategory;
use App\Models\Prescription;
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

test('invalid build leaves every downstream aggregate unchanged', function () {
    // Two lens packages - invalid
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'subtotal' => 6000,
        'total' => 6000,
    ]);

    $lensCategory = LensCategory::factory()->withPrice(3000)->create();

    $quotation->items()->create([
        'description' => 'Lens 1',
        'quantity' => 1,
        'unit_price' => 3000,
        'amount' => 3000,
        'lens_category_id' => $lensCategory->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::LensPackage,
    ]);

    $quotation->items()->create([
        'description' => 'Lens 2',
        'quantity' => 1,
        'unit_price' => 3000,
        'amount' => 3000,
        'lens_category_id' => $lensCategory->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::LensPackage,
    ]);

    app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );
})->throws(ValidationException::class, 'exactly one lens package');

test('corrective confirmation requires current prescription', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'subtotal' => 8000,
        'total' => 8000,
        'prescription_id' => null, // No prescription
    ]);

    $lensCategory = LensCategory::factory()->withPrice(3000)->create();

    $quotation->items()->create([
        'description' => 'Lens',
        'quantity' => 1,
        'unit_price' => 3000,
        'amount' => 3000,
        'lens_category_id' => $lensCategory->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::LensPackage,
    ]);

    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => ProductVariant::factory()->create()->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );
})->throws(ValidationException::class, 'A current prescription is required');

test('valid corrective confirmation succeeds', function () {
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
        'description' => 'Lens',
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

    expect($result['quotation']->status)->toBe(QuotationStatus::Accepted)
        ->and($result['optical_order'])->not->toBeNull()
        ->and($result['billing_record'])->not->toBeNull();
});

test('concurrent confirmation remains idempotent', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    $prescription = Prescription::factory()->linkedToEncounter($encounter)->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 20]);
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();

    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'prescription_id' => $prescription->id,
        'subtotal' => 8000,
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
        'description' => 'Lens',
        'quantity' => 1,
        'unit_price' => 3000,
        'amount' => 3000,
        'lens_category_id' => $lensCategory->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::LensPackage,
    ]);

    $first = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );

    $second = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation->fresh(),
        confirmer: $this->staff,
    );

    expect($first['optical_order']->id)->toBe($second['optical_order']->id)
        ->and($first['billing_record']->id)->toBe($second['billing_record']->id);
});

test('non-corrective product confirmation succeeds without prescription', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'subtotal' => 5000,
        'total' => 5000,
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

    $result = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );

    expect($result['optical_order'])->not->toBeNull();
});
