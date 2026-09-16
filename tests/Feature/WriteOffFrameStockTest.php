<?php

use App\Actions\Inventory\WriteOffFrameStock;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('writing off a frame batch updates the batch, aggregate, and ledger', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->create(['product_type' => 'frame']))
        ->create(['stock_quantity' => 5]);
    $actor = User::factory()->staff()->create();
    $batch = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'FRAME-001',
        'expires_on' => null,
        'received_quantity' => 5,
        'quantity_on_hand' => 5,
    ]);

    $movement = app(WriteOffFrameStock::class)->handle(
        variant: $variant,
        quantity: 2,
        inventoryLotId: $batch->id,
        actor: $actor,
        notes: 'Frame scratched during display',
    );

    expect($variant->fresh()->stock_quantity)->toBe(3)
        ->and($batch->fresh()->quantity_on_hand)->toBe(3)
        ->and($movement->inventory_lot_id)->toBe($batch->id)
        ->and($movement->quantity_change)->toBe(-2)
        ->and(InventoryMovement::query()->sole()->inventory_lot_id)->toBe($batch->id);
});

test('frame write-off rejects an expiring lot', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->create(['product_type' => 'frame']))
        ->create(['stock_quantity' => 5]);
    $actor = User::factory()->staff()->create();
    $lot = InventoryLot::factory()->for($variant, 'variant')->create([
        'expires_on' => now()->addMonth()->toDateString(),
    ]);

    expect(fn () => app(WriteOffFrameStock::class)->handle(
        variant: $variant,
        quantity: 1,
        inventoryLotId: $lot->id,
        actor: $actor,
        notes: 'Incorrect batch',
    ))->toThrow(ValidationException::class);
});

test('frame write-off requires a panel role', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->create(['product_type' => 'frame']))
        ->create(['stock_quantity' => 1]);
    $patient = User::factory()->patient()->create();

    expect(fn () => app(WriteOffFrameStock::class)->handle(
        variant: $variant,
        quantity: 1,
        inventoryLotId: 1,
        actor: $patient,
        notes: 'Frame damaged',
    ))->toThrow(AuthorizationException::class);
});
