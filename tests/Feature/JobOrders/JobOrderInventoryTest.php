<?php

use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Enums\JobOrderStatus;
use App\Models\Brand;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('commit inventory decrements stock for each item', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant1 = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 10]);
    $variant2 = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 5]);

    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    JobOrderItem::factory()->create(['job_order_id' => $jobOrder->id, 'product_variant_id' => $variant1->id, 'quantity' => 2]);
    JobOrderItem::factory()->create(['job_order_id' => $jobOrder->id, 'product_variant_id' => $variant2->id, 'quantity' => 1]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);

    expect($variant1->fresh()->stock_quantity)->toBe(8)
        ->and($variant2->fresh()->stock_quantity)->toBe(4);
});

test('commit rejects when stock is insufficient', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 1]);

    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    JobOrderItem::factory()->create(['job_order_id' => $jobOrder->id, 'product_variant_id' => $variant->id, 'quantity' => 3]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);
})->throws(ValidationException::class);

test('commit rejects non-queued job order', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::InProgress]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);
})->throws(ValidationException::class);

test('cancel reverses inventory for committed job order', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 7]);

    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    JobOrderItem::factory()->create(['job_order_id' => $jobOrder->id, 'product_variant_id' => $variant->id, 'quantity' => 2]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);
    expect($variant->fresh()->stock_quantity)->toBe(5);

    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'cancelled');
    expect($variant->fresh()->stock_quantity)->toBe(7);
});

test('cancelling a frame order restores the exact source batches', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 7]);
    $oldBatch = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'FRAME-OLD',
        'expires_on' => null,
        'received_at' => now()->subDays(10),
        'received_quantity' => 4,
        'quantity_on_hand' => 4,
    ]);
    $newBatch = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'FRAME-NEW',
        'expires_on' => null,
        'received_at' => now()->subDays(2),
        'received_quantity' => 3,
        'quantity_on_hand' => 3,
    ]);

    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $variant->id,
        'quantity' => 5,
    ]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);
    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'cancelled');

    $reversals = InventoryMovement::query()
        ->where('job_order_id', $jobOrder->id)
        ->where('quantity_change', '>', 0)
        ->orderBy('id')
        ->get();

    expect($variant->fresh()->stock_quantity)->toBe(7)
        ->and($oldBatch->fresh()->quantity_on_hand)->toBe(4)
        ->and($newBatch->fresh()->quantity_on_hand)->toBe(3)
        ->and($reversals->pluck('inventory_lot_id')->all())->toBe([$oldBatch->id, $newBatch->id])
        ->and($reversals->pluck('quantity_change')->all())->toBe([4, 1]);
});

test('cancel is idempotent — stock restored only once', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 7]);

    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    JobOrderItem::factory()->create(['job_order_id' => $jobOrder->id, 'product_variant_id' => $variant->id, 'quantity' => 2]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);
    expect($variant->fresh()->stock_quantity)->toBe(5);

    // Cancel once — restores to 7
    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'cancelled');
    expect($variant->fresh()->stock_quantity)->toBe(7);

    // Cannot cancel again — already cancelled
    app(UpdateJobOrderStatus::class)->handle($jobOrder->fresh(), 'cancelled');
    expect($variant->fresh()->stock_quantity)->toBe(7); // No double restore
})->throws(ValidationException::class);

test('valid status transitions are enforced', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Queued,
        'supplier_invoice_number' => 'SUP-INV-1001',
    ]);

    // queued → in_progress (valid)
    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'in_progress');
    expect($jobOrder->fresh()->status)->toBe(JobOrderStatus::InProgress);

    // in_progress → ready_for_dispensing (valid)
    app(UpdateJobOrderStatus::class)->handle($jobOrder->fresh(), 'ready_for_dispensing');
    expect($jobOrder->fresh()->status)->toBe(JobOrderStatus::ReadyForDispensing);

    // ready_for_dispensing → queued (invalid)
    app(UpdateJobOrderStatus::class)->handle($jobOrder->fresh(), 'queued');
})->throws(ValidationException::class);

test('status transitions set timestamps', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Queued,
        'supplier_invoice_number' => 'SUP-INV-1002',
    ]);

    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'in_progress');
    expect($jobOrder->fresh()->started_at)->not->toBeNull();

    app(UpdateJobOrderStatus::class)->handle($jobOrder->fresh(), 'ready_for_dispensing');
    expect($jobOrder->fresh()->ready_at)->not->toBeNull();

    app(UpdateJobOrderStatus::class)->handle($jobOrder->fresh(), 'dispensed');
    expect($jobOrder->fresh()->dispensed_at)->not->toBeNull();
});

test('supplier invoice number is required before a job order is marked ready', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::InProgress,
        'uses_external_supplier' => true,
    ]);

    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'ready_for_dispensing');
})->throws(ValidationException::class, 'Enter the supplier invoice number before marking this job order ready.');

test('job order can be marked ready when its supplier invoice number is recorded', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::InProgress,
        'supplier_invoice_number' => 'SUP-INV-1003',
    ]);

    $updatedJobOrder = app(UpdateJobOrderStatus::class)->handle($jobOrder, 'ready_for_dispensing');

    expect($updatedJobOrder->status)->toBe(JobOrderStatus::ReadyForDispensing)
        ->and($updatedJobOrder->supplier_invoice_number)->toBe('SUP-INV-1003');
});
