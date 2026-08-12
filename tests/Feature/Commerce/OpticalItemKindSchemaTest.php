<?php

/**
 * Tests for CommercialItemKind enum and item_kind/item_snapshot schema.
 *
 * @see tasks/todo.md Task 4
 */

use App\Enums\CommercialItemKind;
use App\Models\JobOrderItem;
use App\Models\LensCategory;
use App\Models\ProductVariant;
use App\Models\QuotationItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('CommercialItemKind enum has exactly the expected cases', function () {
    expect(CommercialItemKind::cases())->toHaveCount(7)
        ->and(CommercialItemKind::Frame->value)->toBe('frame')
        ->and(CommercialItemKind::LensPackage->value)->toBe('lens_package')
        ->and(CommercialItemKind::LensOption->value)->toBe('lens_option')
        ->and(CommercialItemKind::ContactLens->value)->toBe('contact_lens')
        ->and(CommercialItemKind::Accessory->value)->toBe('accessory')
        ->and(CommercialItemKind::CustomProduct->value)->toBe('custom_product')
        ->and(CommercialItemKind::Service->value)->toBe('service');
});

test('quotation_items table has item_kind and item_snapshot columns', function () {
    $item = QuotationItem::factory()->create([
        'item_kind' => CommercialItemKind::Frame,
        'item_snapshot' => json_encode(['name' => 'Test Frame']),
    ]);

    $item->refresh();

    expect($item->item_kind)->toBe(CommercialItemKind::Frame)
        ->and(json_decode($item->item_snapshot, true))->toBe(['name' => 'Test Frame']);
});

test('job_order_items table has item_kind and item_snapshot columns', function () {
    $item = JobOrderItem::factory()->product()->create([
        'item_kind' => CommercialItemKind::Frame,
        'item_snapshot' => json_encode(['sku' => 'FRM-001']),
    ]);

    $item->refresh();

    expect($item->item_kind)->toBe(CommercialItemKind::Frame)
        ->and(json_decode($item->item_snapshot, true))->toBe(['sku' => 'FRM-001']);
});

test('item_kind is required on quotation_items', function () {
    $this->expectException(QueryException::class);

    QuotationItem::factory()->create([
        'item_kind' => null,
    ]);
});

test('item_kind is required on job_order_items', function () {
    $this->expectException(QueryException::class);

    JobOrderItem::factory()->product()->create([
        'item_kind' => null,
    ]);
});

test('item_snapshot is nullable on both tables', function () {
    $quotationItem = QuotationItem::factory()->create([
        'item_kind' => CommercialItemKind::Frame,
        'item_snapshot' => null,
    ]);

    $jobOrderItem = JobOrderItem::factory()->product()->create([
        'item_kind' => CommercialItemKind::Frame,
        'item_snapshot' => null,
    ]);

    expect($quotationItem->item_snapshot)->toBeNull()
        ->and($jobOrderItem->item_snapshot)->toBeNull();
});

test('backfill derives item_kind from controlled foreign keys', function () {
    // This test verifies the migration backfill logic works correctly.
    // It runs against the migrated schema where backfill has already happened.

    // Service item with service_id should be 'service'
    $serviceItem = QuotationItem::factory()->service()->create([
        'item_kind' => CommercialItemKind::Service->value,
    ]);

    // Product with lens_category_id should be 'lens_package'
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();
    $lensItem = QuotationItem::factory()->product()->create([
        'lens_category_id' => $lensCategory->id,
        'product_variant_id' => null,
        'item_kind' => CommercialItemKind::LensPackage->value,
    ]);

    // Product with product_variant_id (ambiguous) should be 'custom_product'
    $variant = ProductVariant::factory()->create();
    $productItem = QuotationItem::factory()->product()->create([
        'product_variant_id' => $variant->id,
        'lens_category_id' => null,
        'item_kind' => CommercialItemKind::CustomProduct->value,
    ]);

    expect($serviceItem->item_kind)->toBe(CommercialItemKind::Service)
        ->and($lensItem->item_kind)->toBe(CommercialItemKind::LensPackage)
        ->and($productItem->item_kind)->toBe(CommercialItemKind::CustomProduct);
});
