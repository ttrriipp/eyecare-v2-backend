<?php

use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Enums\CommercialItemKind;
use App\Enums\JobOrderStatus;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\JobOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-08-28 10:00:00'));

afterEach(fn () => Carbon::setTestNow());

test('cancelling a contact lens order restores each committed lot exactly', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create(['stock_quantity' => 9]);
    $earlier = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'EARLIER',
        'expires_on' => '2026-09-30',
        'received_quantity' => 4,
        'quantity_on_hand' => 4,
    ]);
    $later = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'LATER',
        'expires_on' => '2026-10-31',
        'received_quantity' => 5,
        'quantity_on_hand' => 5,
    ]);
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Contact lenses',
        'quantity' => 7,
        'unit_price' => 1200,
        'amount' => 8400,
        'product_variant_id' => $variant->id,
        'item_kind' => CommercialItemKind::CustomProduct,
    ]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);
    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'cancelled');

    $reversals = InventoryMovement::query()
        ->where('job_order_id', $jobOrder->id)
        ->whereHas('movementType', fn ($query) => $query->where('name', 'order_reversal'))
        ->orderBy('id')
        ->get();

    expect($earlier->fresh()->quantity_on_hand)->toBe(4)
        ->and($later->fresh()->quantity_on_hand)->toBe(5)
        ->and($variant->fresh()->stock_quantity)->toBe(9)
        ->and($reversals)->toHaveCount(2)
        ->and($reversals->pluck('inventory_lot_id')->all())
        ->toBe([$earlier->id, $later->id])
        ->and($reversals->pluck('quantity_change')->all())
        ->toBe([4, 3])
        ->and($reversals->pluck('previous_stock')->all())
        ->toBe([2, 6])
        ->and($reversals->pluck('new_stock')->all())
        ->toBe([6, 9]);
});

test('cancelling a contact lens order refuses an untraceable aggregate commitment', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create(['stock_quantity' => 2]);
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Contact lenses',
        'quantity' => 2,
        'unit_price' => 1200,
        'amount' => 2400,
        'product_variant_id' => $variant->id,
        'item_kind' => CommercialItemKind::CustomProduct,
    ]);
    $commitmentType = InventoryMovementType::query()
        ->firstOrCreate(['name' => 'order_commitment']);
    InventoryMovement::query()->create([
        'product_variant_id' => $variant->id,
        'job_order_id' => $jobOrder->id,
        'inventory_movement_type_id' => $commitmentType->id,
        'quantity_change' => -2,
        'previous_stock' => 4,
        'new_stock' => 2,
    ]);

    expect(fn () => app(UpdateJobOrderStatus::class)->handle($jobOrder, 'cancelled'))
        ->toThrow(ValidationException::class);

    expect($jobOrder->fresh()->status)->toBe(JobOrderStatus::Queued)
        ->and($variant->fresh()->stock_quantity)->toBe(2)
        ->and(InventoryMovement::query()->where('inventory_movement_type_id', $commitmentType->id)->count())
        ->toBe(1);
});
