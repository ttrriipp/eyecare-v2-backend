<?php

/**
 * Tests for direct Quotation relationships.
 */

use App\Enums\QuotationStatus;
use App\Enums\TransactionItemType;
use App\Models\JobOrder;
use App\Models\LensCategory;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('quotation has direct items relationship', function () {
    $quotation = Quotation::factory()->create();

    QuotationItem::factory()->count(2)->create([
        'quotation_id' => $quotation->id,
    ]);

    expect($quotation->items)->toHaveCount(2);
});

test('quotation has direct job order relationship', function () {
    $quotation = Quotation::factory()->create();

    $jobOrder = JobOrder::factory()->create([
        'quotation_id' => $quotation->id,
        'patient_id' => $quotation->patient_id,
    ]);

    expect($quotation->jobOrder)->not->toBeNull()
        ->and($quotation->jobOrder->id)->toBe($jobOrder->id);
});

test('quotation item has direct quotation relationship', function () {
    $quotation = Quotation::factory()->create();

    $item = QuotationItem::factory()->create([
        'quotation_id' => $quotation->id,
    ]);

    expect($item->quotation)->not->toBeNull()
        ->and($item->quotation->id)->toBe($quotation->id);
});

test('factory creates direct draft with totals', function () {
    $quotation = Quotation::factory()
        ->withTotals(10000, 1500)
        ->create();

    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and((float) $quotation->subtotal)->toBe(10000.0)
        ->and((float) $quotation->discount_amount)->toBe(1500.0)
        ->and((float) $quotation->total)->toBe(8500.0);
});

test('factory creates presented quotation with metadata', function () {
    $quotation = Quotation::factory()->presented()->create();

    expect($quotation->status)->toBe(QuotationStatus::Presented)
        ->and($quotation->presented_by)->not->toBeNull()
        ->and($quotation->presented_at)->not->toBeNull();
});

test('factory creates accepted quotation with metadata', function () {
    $quotation = Quotation::factory()->accepted()->create();

    expect($quotation->status)->toBe(QuotationStatus::Accepted)
        ->and($quotation->presented_by)->not->toBeNull()
        ->and($quotation->presented_at)->not->toBeNull()
        ->and($quotation->confirmed_by)->not->toBeNull()
        ->and($quotation->confirmed_at)->not->toBeNull();
});

test('direct draft with heterogeneous items', function () {
    $quotation = Quotation::factory()
        ->withTotals(12250)
        ->create();

    $variant = ProductVariant::factory()->create();
    $lensCategory = LensCategory::factory()->create(['price' => 3000]);

    QuotationItem::factory()->create([
        'quotation_id' => $quotation->id,
        'description' => 'Classic Rectangle Frame',
        'quantity' => 1,
        'unit_price' => 4500,
        'amount' => 4500,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
    ]);

    QuotationItem::factory()->create([
        'quotation_id' => $quotation->id,
        'description' => 'Single Vision Lens',
        'quantity' => 2,
        'unit_price' => 3000,
        'amount' => 6000,
        'lens_category_id' => $lensCategory->id,
        'item_type' => TransactionItemType::Product,
    ]);

    QuotationItem::factory()->create([
        'quotation_id' => $quotation->id,
        'description' => 'Custom fitting',
        'quantity' => 1,
        'unit_price' => 750,
        'amount' => 750,
        'item_type' => TransactionItemType::Service,
    ]);

    QuotationItem::factory()->create([
        'quotation_id' => $quotation->id,
        'description' => 'Anti-reflective coating',
        'quantity' => 1,
        'unit_price' => 1000,
        'amount' => 1000,
        'item_type' => TransactionItemType::Service,
    ]);

    expect($quotation->items)->toHaveCount(4)
        ->and($quotation->items->whereNotNull('product_variant_id'))->toHaveCount(1)
        ->and($quotation->items->whereNotNull('lens_category_id'))->toHaveCount(1)
        ->and($quotation->items->whereNull('product_variant_id')->whereNull('lens_category_id'))->toHaveCount(2);

    expect((float) $quotation->total)->toBe(12250.0);
});

test('quotation presenter relationship', function () {
    $staff = User::factory()->staff()->create();

    $quotation = Quotation::factory()->create([
        'presented_by' => $staff->id,
        'presented_at' => now(),
    ]);

    expect($quotation->presenter)->not->toBeNull()
        ->and($quotation->presenter->id)->toBe($staff->id);
});

test('quotation confirmer relationship', function () {
    $staff = User::factory()->staff()->create();

    $quotation = Quotation::factory()->create([
        'confirmed_by' => $staff->id,
        'confirmed_at' => now(),
    ]);

    expect($quotation->confirmer)->not->toBeNull()
        ->and($quotation->confirmer->id)->toBe($staff->id);
});

test('quotation casts monetary fields correctly', function () {
    $quotation = Quotation::factory()->create([
        'subtotal' => 10000.50,
        'discount_amount' => 1500.75,
        'total' => 8499.75,
    ]);

    expect($quotation->subtotal)->toBe('10000.50')
        ->and($quotation->discount_amount)->toBe('1500.75')
        ->and($quotation->total)->toBe('8499.75');
});
