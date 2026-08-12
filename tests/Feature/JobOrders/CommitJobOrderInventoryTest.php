<?php

use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Enums\JobOrderStatus;
use App\Models\JobOrder;
use App\Models\ProductVariant;

test('commits inventory for all product items by default', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Frame',
        'quantity' => 2,
        'unit_price' => 2500,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
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
        ],
        [
            'description' => 'Regular lens',
            'quantity' => 1,
            'unit_price' => 1500,
            'amount' => 1500,
            'product_variant_id' => $normal->id,
        ],
    ]);

    app(CommitJobOrderInventory::class)->handle($jobOrder, excludeProductVariantIds: [$excluded->id]);

    expect($excluded->fresh()->stock_quantity)->toBe(9)
        ->and($normal->fresh()->stock_quantity)->toBe(9);
});
