<?php

use App\Actions\Inventory\ReceiveFrameStock;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-08-28 10:00:00'));

afterEach(fn () => Carbon::setTestNow());

test('an administrator can receive a frame with a custom batch number', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->create(['product_type' => 'frame']))
        ->create(['stock_quantity' => 0]);
    $receiver = User::factory()->admin()->create();

    $movement = app(ReceiveFrameStock::class)->handle(
        variant: $variant,
        quantity: 4,
        batchNumber: '  FRAME-001  ',
        receiver: $receiver,
        purchasedAt: '2026-08-20',
        sourceReference: 'PO-42',
        notes: 'Frame delivery',
    );

    $batch = InventoryLot::query()->sole();

    expect($variant->fresh()->stock_quantity)->toBe(4)
        ->and($batch->lot_number)->toBe('FRAME-001')
        ->and($batch->expires_on)->toBeNull()
        ->and($batch->received_quantity)->toBe(4)
        ->and($batch->quantity_on_hand)->toBe(4)
        ->and($batch->received_at->toDateTimeString())->toBe('2026-08-28 10:00:00')
        ->and($batch->purchased_at->toDateString())->toBe('2026-08-20')
        ->and($batch->received_by)->toBe($receiver->id)
        ->and($movement->inventory_lot_id)->toBe($batch->id)
        ->and($movement->quantity_change)->toBe(4)
        ->and($movement->purchased_at->toDateString())->toBe('2026-08-20');
});

test('receiving a frame generates a batch number when none is provided', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->create(['product_type' => 'frame']))
        ->create(['stock_quantity' => 0]);
    $receiver = User::factory()->staff()->create();

    app(ReceiveFrameStock::class)->handle(
        variant: $variant,
        quantity: 4,
        batchNumber: null,
        receiver: $receiver,
    );

    $batch = InventoryLot::query()->sole();

    expect($batch->lot_number)->toBe(sprintf(
        'FRM-%d-260828-1',
        $variant->id,
    ));
});

test('receiving into an existing frame batch increases its quantities', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->create(['product_type' => 'frame']))
        ->create(['stock_quantity' => 2]);
    $receiver = User::factory()->admin()->create();
    $batch = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'FRAME-001',
        'expires_on' => null,
        'received_quantity' => 2,
        'quantity_on_hand' => 2,
        'purchased_at' => '2026-08-25',
    ]);

    app(ReceiveFrameStock::class)->handle(
        variant: $variant,
        quantity: 3,
        batchNumber: 'FRAME-001',
        receiver: $receiver,
        purchasedAt: '2026-08-20',
    );

    expect($batch->fresh()->received_quantity)->toBe(5)
        ->and($batch->fresh()->quantity_on_hand)->toBe(5)
        ->and($batch->fresh()->purchased_at->toDateString())->toBe('2026-08-20')
        ->and($variant->fresh()->stock_quantity)->toBe(5)
        ->and(InventoryLot::query()->count())->toBe(1);
});

test('staff cannot override the generated frame batch number', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->create(['product_type' => 'frame']))
        ->create();
    $receiver = User::factory()->staff()->create();

    expect(fn () => app(ReceiveFrameStock::class)->handle(
        variant: $variant,
        quantity: 1,
        batchNumber: 'MANUAL-001',
        receiver: $receiver,
    ))->toThrow(AuthorizationException::class);
});

test('frame receiving rejects non-frame variants', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create();
    $receiver = User::factory()->staff()->create();

    expect(fn () => app(ReceiveFrameStock::class)->handle(
        variant: $variant,
        quantity: 1,
        batchNumber: 'FRAME-001',
        receiver: $receiver,
    ))->toThrow(ValidationException::class);
});

test('frame receiving requires a panel role', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->create(['product_type' => 'frame']))
        ->create();
    $patient = User::factory()->patient()->create();

    expect(fn () => app(ReceiveFrameStock::class)->handle(
        variant: $variant,
        quantity: 1,
        batchNumber: 'FRAME-001',
        receiver: $patient,
    ))->toThrow(AuthorizationException::class);
});
