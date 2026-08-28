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
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-08-28 10:00:00'));

afterEach(fn () => Carbon::setTestNow());

test('contact lens commitments allocate earliest-expiring lots and split when needed', function () {
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

    $movements = InventoryMovement::query()
        ->where('job_order_id', $jobOrder->id)
        ->whereHas('movementType', fn ($query) => $query->where('name', 'order_commitment'))
        ->orderBy('id')
        ->get();

    expect($earlier->fresh()->quantity_on_hand)->toBe(0)
        ->and($later->fresh()->quantity_on_hand)->toBe(2)
        ->and($variant->fresh()->stock_quantity)->toBe(2)
        ->and($movements)->toHaveCount(2)
        ->and($movements->pluck('inventory_lot_id')->all())
        ->toBe([$earlier->id, $later->id])
        ->and($movements->pluck('quantity_change')->all())
        ->toBe([-4, -3])
        ->and($movements->pluck('previous_stock')->all())
        ->toBe([9, 5])
        ->and($movements->pluck('new_stock')->all())
        ->toBe([5, 2]);
});

test('contact lens commitments never use expired lots and roll back when usable stock is short', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create(['stock_quantity' => 3]);
    $expired = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'EXPIRED',
        'expires_on' => '2026-08-27',
        'received_quantity' => 2,
        'quantity_on_hand' => 2,
    ]);
    $usable = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'USABLE',
        'expires_on' => '2026-08-29',
        'received_quantity' => 1,
        'quantity_on_hand' => 1,
    ]);
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Contact lenses',
        'quantity' => 2,
        'unit_price' => 1200,
        'amount' => 2400,
        'product_variant_id' => $variant->id,
        'item_kind' => CommercialItemKind::CustomProduct,
    ]);

    expect(fn () => app(CommitJobOrderInventory::class)->handle($jobOrder))
        ->toThrow(ValidationException::class);

    expect($expired->fresh()->quantity_on_hand)->toBe(2)
        ->and($usable->fresh()->quantity_on_hand)->toBe(1)
        ->and($variant->fresh()->stock_quantity)->toBe(3)
        ->and(InventoryMovement::query()->count())->toBe(0);
});

test('contact lens lots expiring today are eligible for commitment', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create(['stock_quantity' => 1]);
    $lot = InventoryLot::factory()->for($variant, 'variant')->create([
        'expires_on' => '2026-08-28',
        'quantity_on_hand' => 1,
        'received_quantity' => 1,
    ]);
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Contact lenses',
        'quantity' => 1,
        'unit_price' => 1200,
        'amount' => 1200,
        'product_variant_id' => $variant->id,
        'item_kind' => CommercialItemKind::CustomProduct,
    ]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);

    expect($lot->fresh()->quantity_on_hand)->toBe(0)
        ->and($variant->fresh()->stock_quantity)->toBe(0);
});
