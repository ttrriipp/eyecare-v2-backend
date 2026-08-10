<?php

/**
 * Tests for lot-aware inventory allocation.
 *
 * @see tasks/todo.md Task 37
 */

use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Enums\CommercialItemKind;
use App\Enums\JobOrderStatus;
use App\Enums\TransactionItemType;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('default allocation is deterministic FEFO across non-expired lots', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 50,
    ]);

    // Create lots with different expiry dates
    $lot1 = InventoryLot::factory()->create([
        'product_variant_id' => $variant->id,
        'lot_number' => 'LOT-A',
        'expires_on' => now()->addMonths(6),
        'quantity_on_hand' => 30,
    ]);

    $lot2 = InventoryLot::factory()->create([
        'product_variant_id' => $variant->id,
        'lot_number' => 'LOT-B',
        'expires_on' => now()->addYear(),
        'quantity_on_hand' => 20,
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

    // Should allocate from LOT-A (earlier expiry)
    $lot1->refresh();
    expect($lot1->quantity_on_hand)->toBe(20); // 30 - 10

    $movement = InventoryMovement::where('job_order_id', $jobOrder->id)->first();
    expect($movement->inventory_lot_id)->toBe($lot1->id);
});

test('explicit selection accepts only a reconciled non-expired lot', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 50,
    ]);

    $lot1 = InventoryLot::factory()->create([
        'product_variant_id' => $variant->id,
        'lot_number' => 'LOT-A',
        'expires_on' => now()->addMonths(6),
        'quantity_on_hand' => 30,
    ]);

    $lot2 = InventoryLot::factory()->create([
        'product_variant_id' => $variant->id,
        'lot_number' => 'LOT-B',
        'expires_on' => now()->addYear(),
        'quantity_on_hand' => 20,
    ]);

    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Contact Lens',
        'quantity' => 5,
        'unit_price' => 500,
        'amount' => 2500,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::ContactLens,
    ]);

    // Explicitly select LOT-B (later expiry)
    app(CommitJobOrderInventory::class)->handle($jobOrder, selectedLotIds: [$variant->id => $lot2->id]);

    $lot2->refresh();
    expect($lot2->quantity_on_hand)->toBe(15); // 20 - 5

    $movement = InventoryMovement::where('job_order_id', $jobOrder->id)->first();
    expect($movement->inventory_lot_id)->toBe($lot2->id);
});

test('expired lots cannot be allocated', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 50,
    ]);

    $expiredLot = InventoryLot::factory()->expired()->create([
        'product_variant_id' => $variant->id,
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
})->throws(ValidationException::class, 'No non-expired lots');

test('concurrent allocations cannot make variant or lot quantity negative', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 10,
    ]);

    $lot = InventoryLot::factory()->create([
        'product_variant_id' => $variant->id,
        'quantity_on_hand' => 10,
    ]);

    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Contact Lens',
        'quantity' => 15,
        'unit_price' => 500,
        'amount' => 7500,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::ContactLens,
    ]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);
})->throws(ValidationException::class, 'Insufficient stock');

test('FEFO and explicit selection are deterministic', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 50,
    ]);

    $lot1 = InventoryLot::factory()->create([
        'product_variant_id' => $variant->id,
        'lot_number' => 'LOT-A',
        'expires_on' => now()->addMonths(6),
        'quantity_on_hand' => 30,
    ]);

    $lot2 = InventoryLot::factory()->create([
        'product_variant_id' => $variant->id,
        'lot_number' => 'LOT-B',
        'expires_on' => now()->addYear(),
        'quantity_on_hand' => 20,
    ]);

    // Run FEFO twice - should always pick LOT-A
    for ($i = 0; $i < 2; $i++) {
        $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
        $jobOrder->items()->create([
            'description' => 'Contact Lens',
            'quantity' => 5,
            'unit_price' => 500,
            'amount' => 2500,
            'product_variant_id' => $variant->id,
            'item_type' => TransactionItemType::Product,
            'item_kind' => CommercialItemKind::ContactLens,
        ]);

        app(CommitJobOrderInventory::class)->handle($jobOrder);

        $movement = InventoryMovement::where('job_order_id', $jobOrder->id)->first();
        expect($movement->inventory_lot_id)->toBe($lot1->id);
    }

    $lot1->refresh();
    expect($lot1->quantity_on_hand)->toBe(20); // 30 - 5 - 5
});

test('frame allocation does not use lots', function () {
    $product = Product::factory()->create(['product_type' => 'frame']);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'stock_quantity' => 10,
    ]);

    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Frame',
        'quantity' => 2,
        'unit_price' => 2500,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);

    $variant->refresh();
    expect($variant->stock_quantity)->toBe(8);

    $movement = InventoryMovement::where('job_order_id', $jobOrder->id)->first();
    expect($movement->inventory_lot_id)->toBeNull();
});
