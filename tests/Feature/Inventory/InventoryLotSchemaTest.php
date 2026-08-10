<?php

/**
 * Tests for inventory lot schema and constraints.
 *
 * @see tasks/todo.md Task 33
 */

use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('variant/lot number is unique', function () {
    $variant = ProductVariant::factory()->create();

    InventoryLot::factory()->create([
        'product_variant_id' => $variant->id,
        'lot_number' => 'LOT-001',
    ]);

    InventoryLot::factory()->create([
        'product_variant_id' => $variant->id,
        'lot_number' => 'LOT-001',
    ]);
})->throws(QueryException::class);

test('lot quantities cannot be negative', function () {
    InventoryLot::factory()->create([
        'quantity_on_hand' => -5,
    ]);
})->throws(QueryException::class);

test('movements may reference an exact lot', function () {
    $variant = ProductVariant::factory()->create();
    $lot = InventoryLot::factory()->create([
        'product_variant_id' => $variant->id,
    ]);

    $movement = InventoryMovement::factory()->create([
        'product_variant_id' => $variant->id,
        'inventory_lot_id' => $lot->id,
    ]);

    expect($movement->lot->id)->toBe($lot->id);
});

test('existing non-lot movements remain valid', function () {
    $variant = ProductVariant::factory()->create();

    $movement = InventoryMovement::factory()->create([
        'product_variant_id' => $variant->id,
        'inventory_lot_id' => null,
    ]);

    expect($movement->lot)->toBeNull();
});

test('lot receipt/expiry values use date/time types', function () {
    $lot = InventoryLot::factory()->create([
        'expires_on' => '2027-06-15',
        'received_at' => '2026-08-10 10:00:00',
    ]);

    expect($lot->expires_on->format('Y-m-d'))->toBe('2027-06-15')
        ->and($lot->received_at->format('Y-m-d H:i:s'))->toBe('2026-08-10 10:00:00');
});

test('lot identifies receiving actor', function () {
    $lot = InventoryLot::factory()->create();

    expect($lot->receivedBy)->not->toBeNull()
        ->and($lot->received_by)->toBeGreaterThan(0);
});

test('lot has source reference', function () {
    $lot = InventoryLot::factory()->create([
        'source_reference' => 'PO-1234',
    ]);

    expect($lot->source_reference)->toBe('PO-1234');
});

test('lot can have null source reference', function () {
    $lot = InventoryLot::factory()->create([
        'source_reference' => null,
    ]);

    expect($lot->source_reference)->toBeNull();
});
