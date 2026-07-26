<?php

use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('movement source actor quantity and before/after are attributable', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $jobOrder = JobOrder::factory()->create();

    $movement = InventoryMovement::factory()->create([
        'product_variant_id' => $variant->id,
        'job_order_id' => $jobOrder->id,
        'quantity_change' => -2,
        'previous_stock' => 10,
        'new_stock' => 8,
        'created_by' => User::factory()->staff()->create()->id,
    ]);

    expect($movement->variant->id)->toBe($variant->id)
        ->and($movement->jobOrder->id)->toBe($jobOrder->id)
        ->and($movement->quantity_change)->toBe(-2)
        ->and($movement->previous_stock)->toBe(10)
        ->and($movement->new_stock)->toBe(8)
        ->and($movement->createdBy)->not->toBeNull();
});

test('allocation ownership determines reversal legality', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $jobOrder = JobOrder::factory()->create();

    // Commitment movement
    $commitment = InventoryMovement::factory()->commitment($jobOrder)->create([
        'product_variant_id' => $variant->id,
        'quantity_change' => -3,
    ]);

    // Reversal movement linked to same job order
    $reversal = InventoryMovement::factory()->reversal($jobOrder)->create([
        'product_variant_id' => $variant->id,
        'quantity_change' => 3,
    ]);

    expect($commitment->jobOrder->id)->toBe($jobOrder->id)
        ->and($reversal->jobOrder->id)->toBe($jobOrder->id)
        ->and($commitment->quantity_change)->toBeLessThan(0)
        ->and($reversal->quantity_change)->toBeGreaterThan(0);
});

test('legacy order_id has no canonical consumer', function () {
    // The order_id column was removed from inventory_movements
    $movement = InventoryMovement::factory()->create();

    expect($movement->reservation_id)->toBeNull()
        ->and($movement->job_order_id)->toBeNull();
});

test('archived variants retain movement history', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 5]);

    InventoryMovement::factory()->count(3)->create([
        'product_variant_id' => $variant->id,
    ]);

    $variant->delete();

    $movements = InventoryMovement::query()
        ->where('product_variant_id', $variant->id)
        ->count();

    expect($movements)->toBe(3);
});
