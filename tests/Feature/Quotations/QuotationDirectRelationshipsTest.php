<?php

/**
 * Tests for direct Quotation relationships introduced in Task A3.
 *
 * Verifies that Quotation::items() and Quotation::jobOrder() use direct keys
 * and that factories can create direct drafts without revisions.
 */

use App\Enums\QuotationStatus;
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

    // Create items directly linked to quotation (no revision)
    $item1 = QuotationItem::factory()->direct($quotation)->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $item2 = QuotationItem::factory()->direct($quotation)->create([
        'description' => 'Lens',
        'quantity' => 2,
        'unit_price' => 2000,
        'amount' => 4000,
    ]);

    expect($quotation->items)->toHaveCount(2)
        ->and($quotation->items->pluck('id')->toArray())->toContain($item1->id, $item2->id);
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

    $item = QuotationItem::factory()->direct($quotation)->create();

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

    // Frame
    QuotationItem::factory()->direct($quotation)->create([
        'description' => 'Classic Rectangle Frame',
        'quantity' => 1,
        'unit_price' => 4500,
        'amount' => 4500,
        'product_variant_id' => $variant->id,
    ]);

    // Lens category
    QuotationItem::factory()->direct($quotation)->create([
        'description' => 'Single Vision Lens',
        'quantity' => 2,
        'unit_price' => 3000,
        'amount' => 6000,
        'lens_category_id' => $lensCategory->id,
    ]);

    // Service
    QuotationItem::factory()->direct($quotation)->create([
        'description' => 'Custom fitting',
        'quantity' => 1,
        'unit_price' => 750,
        'amount' => 750,
    ]);

    // Custom charge
    QuotationItem::factory()->direct($quotation)->create([
        'description' => 'Anti-reflective coating',
        'quantity' => 1,
        'unit_price' => 1000,
        'amount' => 1000,
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
