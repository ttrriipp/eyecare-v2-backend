<?php

/**
 * Tests for ReconcileContactLensLots action.
 *
 * @see tasks/todo.md Task 34
 */

use App\Actions\Inventory\ReconcileContactLensLots;
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
    $this->admin = User::factory()->admin()->create();
    $this->staff = User::factory()->staff()->create();
});

test('allocations require real lot/expiry values and sum exactly to current aggregate', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 50,
    ]);

    app(ReconcileContactLensLots::class)->handle($variant, [
        ['lot_number' => 'LOT-001', 'expires_on' => '2027-06-15', 'quantity' => 30],
        ['lot_number' => 'LOT-002', 'expires_on' => '2027-12-15', 'quantity' => 20],
    ], $this->admin);

    expect(InventoryLot::where('product_variant_id', $variant->id)->count())->toBe(2);

    $lot1 = InventoryLot::where('product_variant_id', $variant->id)->where('lot_number', 'LOT-001')->first();
    expect($lot1->quantity_on_hand)->toBe(30);
});

test('action creates no restock and no fabricated legacy lot', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 50,
    ]);

    app(ReconcileContactLensLots::class)->handle($variant, [
        ['lot_number' => 'LOT-001', 'expires_on' => '2027-06-15', 'quantity' => 50],
    ], $this->admin);

    // Stock unchanged
    $variant->refresh();
    expect($variant->stock_quantity)->toBe(50);

    // Source reference is reconciliation
    $lot = InventoryLot::where('product_variant_id', $variant->id)->first();
    expect($lot->source_reference)->toBe('Reconciliation');
});

test('reconciliation is atomic and rejects non-admin actors', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 50,
    ]);

    app(ReconcileContactLensLots::class)->handle($variant, [
        ['lot_number' => 'LOT-001', 'expires_on' => '2027-06-15', 'quantity' => 50],
    ], $this->staff);
})->throws(ValidationException::class, 'Only an admin');

test('allocations must sum to current stock', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 50,
    ]);

    app(ReconcileContactLensLots::class)->handle($variant, [
        ['lot_number' => 'LOT-001', 'expires_on' => '2027-06-15', 'quantity' => 30],
    ], $this->admin);
})->throws(ValidationException::class, 'must sum exactly');

test('duplicate lot numbers are rejected', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 50,
    ]);

    app(ReconcileContactLensLots::class)->handle($variant, [
        ['lot_number' => 'LOT-001', 'expires_on' => '2027-06-15', 'quantity' => 30],
        ['lot_number' => 'LOT-001', 'expires_on' => '2027-12-15', 'quantity' => 20],
    ], $this->admin);
})->throws(ValidationException::class, 'Duplicate lot number');

test('non-contact-lens variants are rejected', function () {
    $product = Product::factory()->create(['product_type' => 'frame']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 50,
    ]);

    app(ReconcileContactLensLots::class)->handle($variant, [
        ['lot_number' => 'LOT-001', 'expires_on' => '2027-06-15', 'quantity' => 50],
    ], $this->admin);
})->throws(ValidationException::class, 'Only contact-lens variants');
