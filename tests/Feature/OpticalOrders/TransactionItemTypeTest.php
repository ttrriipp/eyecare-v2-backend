<?php

/**
 * Tests for TransactionItemType enum and model casts.
 */

use App\Enums\TransactionItemType;
use App\Models\JobOrderItem;
use App\Models\QuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('TransactionItemType enum has correct values', function () {
    expect(TransactionItemType::Product->value)->toBe('product')
        ->and(TransactionItemType::Service->value)->toBe('service')
        ->and(TransactionItemType::LegacyOther->value)->toBe('legacy_other');
});

test('quotation item persists product type', function () {
    $item = QuotationItem::factory()->product()->create();

    expect($item->fresh()->item_type)->toBe(TransactionItemType::Product);
});

test('quotation item persists service type', function () {
    $item = QuotationItem::factory()->service()->create();

    expect($item->fresh()->item_type)->toBe(TransactionItemType::Service);
});

test('job order item persists product type', function () {
    $item = JobOrderItem::factory()->product()->create();

    expect($item->fresh()->item_type)->toBe(TransactionItemType::Product);
});

test('job order item persists service type', function () {
    $item = JobOrderItem::factory()->service()->create();

    expect($item->fresh()->item_type)->toBe(TransactionItemType::Service);
});

test('quotation item default is service', function () {
    $item = QuotationItem::factory()->create();

    expect($item->item_type)->toBe(TransactionItemType::Service);
});

test('job order item default is service', function () {
    $item = JobOrderItem::factory()->create();

    expect($item->item_type)->toBe(TransactionItemType::Service);
});
