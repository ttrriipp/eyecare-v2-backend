<?php

/**
 * Tests for CommercialItemKind enum cast and item_snapshot array cast.
 *
 * @see tasks/todo.md Task 5
 */

use App\Enums\CommercialItemKind;
use App\Enums\TransactionItemType;
use App\Models\JobOrderItem;
use App\Models\QuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('quotation item casts item_kind to CommercialItemKind enum', function () {
    $item = QuotationItem::factory()->create([
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    expect($item->item_kind)->toBe(CommercialItemKind::Frame)
        ->and($item->item_kind)->toBeInstanceOf(CommercialItemKind::class);
});

test('quotation item casts item_snapshot to array', function () {
    $snapshot = ['sku' => 'FRM-001', 'name' => 'Ray-Ban Aviator'];
    $item = QuotationItem::factory()->create([
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Frame,
        'item_snapshot' => $snapshot,
    ]);

    expect($item->item_snapshot)->toBeArray()
        ->and($item->item_snapshot)->toBe($snapshot);
});

test('job order item casts item_kind to CommercialItemKind enum', function () {
    $item = JobOrderItem::factory()->product()->create([
        'item_kind' => CommercialItemKind::CustomProduct,
    ]);

    expect($item->item_kind)->toBe(CommercialItemKind::CustomProduct)
        ->and($item->item_kind)->toBeInstanceOf(CommercialItemKind::class);
});

test('job order item casts item_snapshot to array', function () {
    $snapshot = ['product_name' => 'Frame', 'variant_name' => 'Black'];
    $item = JobOrderItem::factory()->product()->create([
        'item_kind' => CommercialItemKind::CustomProduct,
        'item_snapshot' => $snapshot,
    ]);

    expect($item->item_snapshot)->toBeArray()
        ->and($item->item_snapshot)->toBe($snapshot);
});

test('job order items remain product-only', function () {
    $this->expectException(InvalidArgumentException::class);

    JobOrderItem::factory()->create([
        'item_type' => TransactionItemType::Service,
    ]);
});

test('factory states produce valid representative kinds', function () {
    $frame = QuotationItem::factory()->frame()->create();
    $lens = QuotationItem::factory()->lensPackage()->create();
    $contact = QuotationItem::factory()->contactLens()->create();
    $service = QuotationItem::factory()->service()->create();

    expect($frame->item_kind)->toBe(CommercialItemKind::Frame)
        ->and($lens->item_kind)->toBe(CommercialItemKind::LensPackage)
        ->and($contact->item_kind)->toBe(CommercialItemKind::ContactLens)
        ->and($service->item_kind)->toBe(CommercialItemKind::Service);
});

test('job order factory states produce valid kinds', function () {
    $frame = JobOrderItem::factory()->frame()->create();
    $lens = JobOrderItem::factory()->lensPackage()->create();

    expect($frame->item_kind)->toBe(CommercialItemKind::Frame)
        ->and($lens->item_kind)->toBe(CommercialItemKind::LensPackage);
});

test('item_snapshot is nullable', function () {
    $item = QuotationItem::factory()->create([
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::CustomProduct,
        'item_snapshot' => null,
    ]);

    expect($item->item_snapshot)->toBeNull();
});
