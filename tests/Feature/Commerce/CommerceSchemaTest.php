<?php

/**
 * Tests for Product/Service item invariants.
 */

use App\Enums\TransactionItemType;
use App\Models\JobOrderItem;
use App\Models\LensCategory;
use App\Models\ProductVariant;
use App\Models\QuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('TransactionItemType has only Product and Service', function () {
    expect(TransactionItemType::cases())->toHaveCount(2)
        ->and(TransactionItemType::Product->value)->toBe('product')
        ->and(TransactionItemType::Service->value)->toBe('service');
});

test('Service quotation item cannot have product variant', function () {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Service items cannot reference a product variant or lens category.');

    QuotationItem::factory()->create([
        'item_type' => TransactionItemType::Service,
        'product_variant_id' => ProductVariant::factory()->create()->id,
        'lens_category_id' => null,
    ]);
});

test('Service quotation item cannot have lens category', function () {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Service items cannot reference a product variant or lens category.');

    QuotationItem::factory()->create([
        'item_type' => TransactionItemType::Service,
        'product_variant_id' => null,
        'lens_category_id' => LensCategory::factory()->create()->id,
    ]);
});

test('Product quotation item can have product variant', function () {
    $item = QuotationItem::factory()->create([
        'item_type' => TransactionItemType::Product,
        'product_variant_id' => ProductVariant::factory()->create()->id,
        'lens_category_id' => null,
    ]);

    expect($item->item_type)->toBe(TransactionItemType::Product);
});

test('Product quotation item can have lens category', function () {
    $item = QuotationItem::factory()->create([
        'item_type' => TransactionItemType::Product,
        'product_variant_id' => null,
        'lens_category_id' => LensCategory::factory()->create()->id,
    ]);

    expect($item->item_type)->toBe(TransactionItemType::Product);
});

test('Job Order item must be Product type', function () {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Job Order items must be Product type.');

    JobOrderItem::factory()->create([
        'item_type' => TransactionItemType::Service,
    ]);
});

test('Job Order item accepts Product type', function () {
    $item = JobOrderItem::factory()->product()->create();

    expect($item->item_type)->toBe(TransactionItemType::Product);
});
