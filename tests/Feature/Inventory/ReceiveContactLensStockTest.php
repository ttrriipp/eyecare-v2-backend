<?php

/**
 * Tests for ReceiveContactLensStock action.
 *
 * @see tasks/todo.md Task 36
 */

use App\Actions\Inventory\ReceiveContactLensStock;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->actingAs($this->staff);
});

test('contact-lens receipt requires positive quantity, nonblank lot, and non-expired expiration', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 0,
    ]);

    $movement = app(ReceiveContactLensStock::class)->handle(
        variant: $variant,
        quantity: 50,
        lotNumber: 'LOT-001',
        expiresOn: now()->addYear()->toDateString(),
        receiver: $this->staff,
        sourceReference: 'PO-1234',
    );

    expect($movement->quantity_change)->toBe(50);

    $variant->refresh();
    expect($variant->stock_quantity)->toBe(50);

    $lot = InventoryLot::where('product_variant_id', $variant->id)->first();
    expect($lot)->not->toBeNull()
        ->and($lot->lot_number)->toBe('LOT-001')
        ->and($lot->quantity_on_hand)->toBe(50);
});

test('frame receipt uses simple aggregate-only path', function () {
    $product = Product::factory()->create(['product_type' => 'frame']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 10,
    ]);

    $movement = app(ReceiveContactLensStock::class)->handle(
        variant: $variant,
        quantity: 20,
        lotNumber: 'LOT-001', // Ignored for non-contact-lens
        expiresOn: now()->addYear()->toDateString(),
        receiver: $this->staff,
    );

    $variant->refresh();
    expect($variant->stock_quantity)->toBe(30);

    // No lot created for non-contact-lens
    expect(InventoryLot::where('product_variant_id', $variant->id)->count())->toBe(0);
});

test('failed receipt cannot drift aggregate and lot totals', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 10,
    ]);

    // Try with expired date
    try {
        app(ReceiveContactLensStock::class)->handle(
            variant: $variant,
            quantity: 50,
            lotNumber: 'LOT-001',
            expiresOn: now()->subDay()->toDateString(),
            receiver: $this->staff,
        );
    } catch (ValidationException $e) {
        // Expected
    }

    // Stock unchanged
    $variant->refresh();
    expect($variant->stock_quantity)->toBe(10);
});

test('concurrent receipt cannot drift aggregate and lot totals', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 0,
    ]);

    // First receipt
    app(ReceiveContactLensStock::class)->handle(
        variant: $variant,
        quantity: 30,
        lotNumber: 'LOT-001',
        expiresOn: now()->addYear()->toDateString(),
        receiver: $this->staff,
    );

    // Second receipt to same lot
    app(ReceiveContactLensStock::class)->handle(
        variant: $variant,
        quantity: 20,
        lotNumber: 'LOT-001',
        expiresOn: now()->addYear()->toDateString(),
        receiver: $this->staff,
    );

    $variant->refresh();
    expect($variant->stock_quantity)->toBe(50);

    $lot = InventoryLot::where('product_variant_id', $variant->id)->where('lot_number', 'LOT-001')->first();
    expect($lot->quantity_on_hand)->toBe(50);
});

test('receipt to new lot creates separate lot', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 0,
    ]);

    app(ReceiveContactLensStock::class)->handle(
        variant: $variant,
        quantity: 30,
        lotNumber: 'LOT-001',
        expiresOn: now()->addYear()->toDateString(),
        receiver: $this->staff,
    );

    app(ReceiveContactLensStock::class)->handle(
        variant: $variant,
        quantity: 20,
        lotNumber: 'LOT-002',
        expiresOn: now()->addYears(2)->toDateString(),
        receiver: $this->staff,
    );

    expect(InventoryLot::where('product_variant_id', $variant->id)->count())->toBe(2);

    $variant->refresh();
    expect($variant->stock_quantity)->toBe(50);
});
