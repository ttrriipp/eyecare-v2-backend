<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('inventory lots have the contact-lens expiry schema', function () {
    expect(Schema::hasTable('inventory_lots'))->toBeTrue()
        ->and(Schema::hasColumns('inventory_lots', [
            'id',
            'product_variant_id',
            'lot_number',
            'expires_on',
            'received_quantity',
            'quantity_on_hand',
            'received_at',
            'received_by',
            'source_reference',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('inventory_movements', 'inventory_lot_id'))->toBeTrue();
});

test('inventory lots enforce a unique lot number per variant', function () {
    $indexes = collect(DB::select(
        <<<'SQL'
        SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS COLUMNS
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'inventory_lots'
            AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        SQL
    ));

    expect($indexes->contains(fn (object $index): bool => $index->COLUMNS === 'product_variant_id,lot_number'))
        ->toBeTrue();
});

test('inventory lots have indexes for variant expiry and global expiry queues', function () {
    $indexes = collect(DB::select(
        <<<'SQL'
        SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS COLUMNS
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'inventory_lots'
            AND NON_UNIQUE = 1
        GROUP BY INDEX_NAME
        SQL
    ));

    expect($indexes->contains(fn (object $index): bool => $index->COLUMNS === 'product_variant_id,expires_on'))
        ->toBeTrue()
        ->and($indexes->contains(fn (object $index): bool => $index->COLUMNS === 'expires_on,quantity_on_hand'))
        ->toBeTrue();
});
