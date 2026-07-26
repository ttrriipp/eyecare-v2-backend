<?php

use App\Actions\JobOrders\CreateJobOrder;
use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\Brand;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('job order creation commits inventory atomically', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 10]);

    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Accepted]);
    $revision = QuotationRevision::factory()->create(['quotation_id' => $quotation->id, 'total' => 2500]);
    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'description' => 'Frame',
        'product_variant_id' => $variant->id,
        'quantity' => 2,
        'unit_price' => 1250,
        'amount' => 2500,
    ]);

    $staff = User::factory()->staff()->create();

    $jobOrder = app(CreateJobOrder::class)->handle($quotation, $staff);

    expect($variant->fresh()->stock_quantity)->toBe(8)
        ->and(InventoryMovement::where('job_order_id', $jobOrder->id)->count())->toBe(1);
});

test('unavailable item rolls back whole job order', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant1 = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 5]);
    $variant2 = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 0]);

    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Accepted]);
    $revision = QuotationRevision::factory()->create(['quotation_id' => $quotation->id]);
    QuotationItem::factory()->create(['quotation_revision_id' => $revision->id, 'product_variant_id' => $variant1->id, 'quantity' => 1, 'amount' => 100]);
    QuotationItem::factory()->create(['quotation_revision_id' => $revision->id, 'product_variant_id' => $variant2->id, 'quantity' => 1, 'amount' => 100]);

    $staff = User::factory()->staff()->create();

    app(CreateJobOrder::class)->handle($quotation, $staff);
})->throws(ValidationException::class);

test('cancellation reverses only unreversed commitments', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 7]);

    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);

    // Record a commitment movement
    $commitmentType = InventoryMovementType::query()->firstOrCreate(['name' => 'order_commitment']);
    InventoryMovement::factory()->create([
        'product_variant_id' => $variant->id,
        'job_order_id' => $jobOrder->id,
        'inventory_movement_type_id' => $commitmentType->id,
        'quantity_change' => -2,
        'previous_stock' => 7,
        'new_stock' => 5,
    ]);
    $variant->update(['stock_quantity' => 5]);

    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'cancelled');

    expect($variant->fresh()->stock_quantity)->toBe(7)
        ->and($jobOrder->fresh()->status)->toBe(JobOrderStatus::Cancelled);
});

test('cancellation is idempotent — stock restored only once', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 7]);

    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);

    $commitmentType = InventoryMovementType::query()->firstOrCreate(['name' => 'order_commitment']);
    InventoryMovement::factory()->create([
        'product_variant_id' => $variant->id,
        'job_order_id' => $jobOrder->id,
        'inventory_movement_type_id' => $commitmentType->id,
        'quantity_change' => -2,
        'previous_stock' => 7,
        'new_stock' => 5,
    ]);
    $variant->update(['stock_quantity' => 5]);

    // Cancel twice
    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'cancelled');
    // Second cancel should fail (already cancelled)
    app(UpdateJobOrderStatus::class)->handle($jobOrder->fresh(), 'cancelled');
})->throws(ValidationException::class);
