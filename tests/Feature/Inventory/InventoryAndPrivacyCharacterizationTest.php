<?php

/**
 * Characterization tests for inventory and patient privacy.
 *
 * Protects aggregate stock, reservation conversion, cancellation reversal,
 * and the existing patient-safe commercial resources.
 *
 * @see tasks/todo.md Task 3
 */

use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Actions\OpticalOrders\CancelOpticalOrder;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Models\BillingRecord;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\JobOrder;
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

// ─── Inventory commitment is quantity-safe ────────────────────────────────────

test('commitment rejects insufficient stock', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 2]);
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Frame',
        'quantity' => 5,
        'unit_price' => 1000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_kind' => 'frame',
    ]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);
})->throws(ValidationException::class, 'Insufficient stock');

test('stock cannot go negative through commitment', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 3]);
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Frame',
        'quantity' => 3,
        'unit_price' => 1000,
        'amount' => 3000,
        'product_variant_id' => $variant->id,
        'item_kind' => 'frame',
    ]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);

    $variant->refresh();
    expect($variant->stock_quantity)->toBe(0);
});

// ─── Cancellation reversal is idempotent ─────────────────────────────────────

test('cancellation reversal restores stock exactly once', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 7]);
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Frame',
        'quantity' => 3,
        'unit_price' => 1000,
        'amount' => 3000,
        'product_variant_id' => $variant->id,
        'item_kind' => 'frame',
    ]);

    // Commit
    app(CommitJobOrderInventory::class)->handle($jobOrder);
    $variant->refresh();
    expect($variant->stock_quantity)->toBe(4);

    // Cancel
    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'cancelled');
    $variant->refresh();
    expect($variant->stock_quantity)->toBe(7);

    // Check exactly one reversal movement
    $reversalType = InventoryMovementType::where('name', 'order_reversal')->first();
    $reversals = InventoryMovement::where('job_order_id', $jobOrder->id)
        ->where('inventory_movement_type_id', $reversalType->id)
        ->count();
    expect($reversals)->toBe(1);
});

test('repeated cancellation does not double-restore stock', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 5]);
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Frame',
        'quantity' => 2,
        'unit_price' => 1000,
        'amount' => 2000,
        'product_variant_id' => $variant->id,
        'item_kind' => 'frame',
    ]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);
    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'cancelled');

    $variant->refresh();
    expect($variant->stock_quantity)->toBe(5);

    // Attempt second cancellation (should fail - already cancelled)
    app(UpdateJobOrderStatus::class)->handle($jobOrder->fresh(), 'cancelled');
})->throws(ValidationException::class);

// ─── Cancellation voids billing when no payments exist ────────────────────────

test('cancellation voids billing when no posted payments exist', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_kind' => 'frame',
    ]);

    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    app(CancelOpticalOrder::class)->handle($jobOrder);

    $billingRecord = BillingRecord::where('job_order_id', $jobOrder->id)->first();
    expect($billingRecord->status)->toBe(BillingRecordStatus::Voided);
});

test('cancellation preserves billing when posted payments exist', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_kind' => 'frame',
    ]);

    $billingRecord = BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 5000,
        'amount_paid' => 2000,
        'balance_due' => 3000,
        'status' => BillingRecordStatus::PartiallyPaid,
    ]);

    // Seed a posted payment
    $billingRecord->payments()->create([
        'amount' => 2000,
        'payment_method' => 'cash',
        'status' => 'posted',
        'recorded_by' => $this->staff->id,
        'recorded_at' => now(),
    ]);

    app(CancelOpticalOrder::class)->handle($jobOrder);

    $billingRecord->refresh();
    // Billing record is preserved (not voided) when payments exist
    expect($billingRecord->status)->toBe(BillingRecordStatus::PartiallyPaid);
});
