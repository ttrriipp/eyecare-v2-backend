<?php

use App\Actions\Inventory\WriteOffContactLensStock;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-08-28 10:00:00'));

afterEach(fn () => Carbon::setTestNow());

test('writing off contact-lens stock reduces the selected lot and records its movement', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create(['stock_quantity' => 10]);
    $actor = User::factory()->staff()->create();
    $lot = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'ACME-001',
        'expires_on' => '2027-06-30',
        'received_quantity' => 10,
        'quantity_on_hand' => 10,
    ]);

    $movement = app(WriteOffContactLensStock::class)->handle(
        variant: $variant,
        quantity: 4,
        inventoryLotId: $lot->id,
        actor: $actor,
        notes: 'Damaged packaging',
    );

    expect($lot->fresh()->quantity_on_hand)->toBe(6)
        ->and($lot->fresh()->received_quantity)->toBe(10)
        ->and($variant->fresh()->stock_quantity)->toBe(6)
        ->and($movement->inventory_lot_id)->toBe($lot->id)
        ->and($movement->quantity_change)->toBe(-4)
        ->and($movement->previous_stock)->toBe(10)
        ->and($movement->new_stock)->toBe(6)
        ->and($movement->notes)->toBe('Damaged packaging');
});

test('writing off more than a lot quantity fails without changing stock', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create(['stock_quantity' => 3]);
    $actor = User::factory()->staff()->create();
    $lot = InventoryLot::factory()->for($variant, 'variant')->create([
        'quantity_on_hand' => 3,
        'received_quantity' => 3,
    ]);

    expect(fn () => app(WriteOffContactLensStock::class)->handle(
        variant: $variant,
        quantity: 4,
        inventoryLotId: $lot->id,
        actor: $actor,
        notes: 'Damaged',
    ))->toThrow(ValidationException::class);

    expect($lot->fresh()->quantity_on_hand)->toBe(3)
        ->and($variant->fresh()->stock_quantity)->toBe(3)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

test('writing off expired stock is allowed for physical disposal', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create(['stock_quantity' => 2]);
    $actor = User::factory()->staff()->create();
    $lot = InventoryLot::factory()->for($variant, 'variant')->create([
        'expires_on' => '2026-08-27',
        'quantity_on_hand' => 2,
        'received_quantity' => 2,
    ]);

    app(WriteOffContactLensStock::class)->handle(
        variant: $variant,
        quantity: 2,
        inventoryLotId: $lot->id,
        actor: $actor,
        notes: 'Expired stock disposal',
    );

    expect($lot->fresh()->quantity_on_hand)->toBe(0)
        ->and($variant->fresh()->stock_quantity)->toBe(0);
});

test('writing off a lot from another variant is rejected', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create(['stock_quantity' => 0]);
    $otherVariant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create(['stock_quantity' => 2]);
    $actor = User::factory()->staff()->create();
    $lot = InventoryLot::factory()->for($otherVariant, 'variant')->create([
        'quantity_on_hand' => 2,
        'received_quantity' => 2,
    ]);

    expect(fn () => app(WriteOffContactLensStock::class)->handle(
        variant: $variant,
        quantity: 1,
        inventoryLotId: $lot->id,
        actor: $actor,
        notes: 'Wrong lot',
    ))->toThrow(ValidationException::class);
});

test('the contact lens write-off action requires a panel role', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create(['stock_quantity' => 1]);
    $patient = User::factory()->patient()->create();
    $lot = InventoryLot::factory()->for($variant, 'variant')->create([
        'quantity_on_hand' => 1,
        'received_quantity' => 1,
    ]);

    expect(fn () => app(WriteOffContactLensStock::class)->handle(
        variant: $variant,
        quantity: 1,
        inventoryLotId: $lot->id,
        actor: $patient,
        notes: 'Damaged',
    ))->toThrow(AuthorizationException::class);
});
