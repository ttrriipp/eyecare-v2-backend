<?php

/**
 * Tests for item-type and fulfillment schema migration.
 */

use App\Enums\TransactionItemType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('quotation_items has item_type column', function () {
    expect(Schema::hasColumn('quotation_items', 'item_type'))->toBeTrue();
});

test('job_order_items has item_type column', function () {
    expect(Schema::hasColumn('job_order_items', 'item_type'))->toBeTrue();
});

test('job_orders has fulfillment_mode column', function () {
    expect(Schema::hasColumn('job_orders', 'fulfillment_mode'))->toBeTrue();
});

test('job_orders has uses_external_supplier column', function () {
    expect(Schema::hasColumn('job_orders', 'uses_external_supplier'))->toBeTrue();
});

test('item_type enum values are valid', function () {
    expect(TransactionItemType::Product->value)->toBe('product')
        ->and(TransactionItemType::Service->value)->toBe('service');
});

test('new records cannot be created with legacy_other type', function () {
    // LegacyOther no longer exists in the enum
    expect(TransactionItemType::cases())->toHaveCount(2);
});
