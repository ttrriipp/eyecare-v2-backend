<?php

/**
 * Tests for lot-aware cancellation reversal.
 *
 * @see tasks/todo.md Task 38
 */

use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Enums\CommercialItemKind;
use App\Enums\JobOrderStatus;
use App\Enums\TransactionItemType;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('cancellation restores aggregate and source-lot quantity once', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 50,
    ]);

    $lot = InventoryLot::factory()->create([
        'product_variant_id' => $variant->id,
        'lot_number' => 'LOT-001',
        'quantity_on_hand' => 30,
    ]);

    // Commit
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Contact Lens',
        'quantity' => 10,
        'unit_price' => 500,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::ContactLens,
    ]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);

    $variant->refresh();
    $lot->refresh();
    expect($variant->stock_quantity)->toBe(40)
        ->and($lot->quantity_on_hand)->toBe(20);

    // Cancel
    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'cancelled');

    $variant->refresh();
    $lot->refresh();
    expect($variant->stock_quantity)->toBe(50)
        ->and($lot->quantity_on_hand)->toBe(30);
});

test('repeated cancellation/reversal attempts create no duplicate restoration', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 50,
    ]);

    $lot = InventoryLot::factory()->create([
        'product_variant_id' => $variant->id,
        'lot_number' => 'LOT-001',
        'quantity_on_hand' => 30,
    ]);

    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Contact Lens',
        'quantity' => 10,
        'unit_price' => 500,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::ContactLens,
    ]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);
    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'cancelled');

    // Second cancellation should fail
    app(UpdateJobOrderStatus::class)->handle($jobOrder->fresh(), 'cancelled');
})->throws(ValidationException::class);

test('frame reversal remains unchanged', function () {
    $product = Product::factory()->create(['product_type' => 'frame']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 10,
    ]);

    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Frame',
        'quantity' => 3,
        'unit_price' => 2500,
        'amount' => 7500,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);

    $variant->refresh();
    expect($variant->stock_quantity)->toBe(7);

    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'cancelled');

    $variant->refresh();
    expect($variant->stock_quantity)->toBe(10);

    // No lot involved
    $reversal = InventoryMovement::where('job_order_id', $jobOrder->id)
        ->where('quantity_change', '>', 0)
        ->first();
    expect($reversal->inventory_lot_id)->toBeNull();
});
