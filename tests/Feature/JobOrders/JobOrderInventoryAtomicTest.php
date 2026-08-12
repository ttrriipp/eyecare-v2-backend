<?php

use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Enums\JobOrderStatus;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\ProductVariant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('job order creation commits inventory atomically', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Queued,
    ]);

    JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $variant->id,
        'quantity' => 3,
    ]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);

    expect($variant->fresh()->stock_quantity)->toBe(7);

    $movements = InventoryMovement::where('product_variant_id', $variant->id)->get();
    expect($movements)->toHaveCount(1)
        ->and($movements->first()->quantity_change)->toBe(-3);
});

test('unavailable item rolls back whole job order', function () {
    $variant1 = ProductVariant::factory()->create(['stock_quantity' => 5]);
    $variant2 = ProductVariant::factory()->create(['stock_quantity' => 1]);

    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Queued,
    ]);

    JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $variant1->id,
        'quantity' => 1,
    ]);

    JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $variant2->id,
        'quantity' => 5,
    ]);

    try {
        app(CommitJobOrderInventory::class)->handle($jobOrder);
    } catch (ValidationException $e) {
        expect($variant1->fresh()->stock_quantity)->toBe(5)
            ->and($variant2->fresh()->stock_quantity)->toBe(1);

        return;
    }

    $this->fail('Expected validation exception for insufficient stock.');
});
