<?php

use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Enums\CommercialItemKind;
use App\Enums\JobOrderStatus;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('commits inventory for all product items by default', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Frame',
        'quantity' => 2,
        'unit_price' => 2500,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);

    expect($variant->fresh()->stock_quantity)->toBe(8);
});

test('skips excluded variants, leaving their stock untouched', function () {
    $excluded = ProductVariant::factory()->create(['stock_quantity' => 9]);
    $normal = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->createMany([
        [
            'description' => 'Already-allocated frame',
            'quantity' => 1,
            'unit_price' => 2500,
            'amount' => 2500,
            'product_variant_id' => $excluded->id,
            'item_kind' => CommercialItemKind::Frame,
        ],
        [
            'description' => 'Regular lens',
            'quantity' => 1,
            'unit_price' => 1500,
            'amount' => 1500,
            'product_variant_id' => $normal->id,
            'item_kind' => CommercialItemKind::Frame,
        ],
    ]);

    app(CommitJobOrderInventory::class)->handle($jobOrder, excludeProductVariantIds: [$excluded->id]);

    expect($excluded->fresh()->stock_quantity)->toBe(9)
        ->and($normal->fresh()->stock_quantity)->toBe(9);
});

test('commits frame inventory from the oldest available batch first', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->create(['product_type' => 'frame']))
        ->create(['stock_quantity' => 7]);
    $oldBatch = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'FRAME-OLD',
        'expires_on' => null,
        'received_at' => Carbon::parse('2026-08-01 09:00:00'),
        'purchased_at' => '2026-08-01',
        'received_quantity' => 4,
        'quantity_on_hand' => 4,
    ]);
    $newBatch = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'FRAME-NEW',
        'expires_on' => null,
        'received_at' => Carbon::parse('2026-08-15 09:00:00'),
        'purchased_at' => '2026-08-15',
        'received_quantity' => 3,
        'quantity_on_hand' => 3,
    ]);
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Frame',
        'quantity' => 5,
        'unit_price' => 2500,
        'amount' => 12500,
        'product_variant_id' => $variant->id,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);

    $commitments = InventoryMovement::query()
        ->where('job_order_id', $jobOrder->id)
        ->where('quantity_change', '<', 0)
        ->orderBy('id')
        ->get();

    expect($variant->fresh()->stock_quantity)->toBe(2)
        ->and($oldBatch->fresh()->quantity_on_hand)->toBe(0)
        ->and($newBatch->fresh()->quantity_on_hand)->toBe(2)
        ->and($commitments->pluck('inventory_lot_id')->all())->toBe([$oldBatch->id, $newBatch->id])
        ->and($commitments->pluck('quantity_change')->all())->toBe([-4, -1]);
});
