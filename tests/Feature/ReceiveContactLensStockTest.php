<?php

use App\Actions\Inventory\ReceiveContactLensStock;
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

test('receiving a contact lens lot normalizes the month and records the movement', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create(['stock_quantity' => 0]);
    $receiver = User::factory()->staff()->create();

    $movement = app(ReceiveContactLensStock::class)->handle(
        variant: $variant,
        quantity: 12,
        lotNumber: '  ACME-001  ',
        expiryMonth: '2027-06',
        receiver: $receiver,
        sourceReference: 'PO-42',
        notes: 'June delivery',
    );

    $lot = InventoryLot::query()->sole();

    expect($variant->fresh()->stock_quantity)->toBe(12)
        ->and($lot->lot_number)->toBe('ACME-001')
        ->and($lot->expires_on->toDateString())->toBe('2027-06-30')
        ->and($lot->received_quantity)->toBe(12)
        ->and($lot->quantity_on_hand)->toBe(12)
        ->and($lot->received_by)->toBe($receiver->id)
        ->and($lot->source_reference)->toBe('PO-42')
        ->and($movement->inventory_lot_id)->toBe($lot->id)
        ->and($movement->quantity_change)->toBe(12)
        ->and($movement->previous_stock)->toBe(0)
        ->and($movement->new_stock)->toBe(12);
});

test('receiving into an existing lot increases its quantities', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create(['stock_quantity' => 4]);
    $receiver = User::factory()->staff()->create();
    $lot = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'ACME-001',
        'expires_on' => '2027-06-30',
        'received_quantity' => 4,
        'quantity_on_hand' => 4,
    ]);

    app(ReceiveContactLensStock::class)->handle(
        variant: $variant,
        quantity: 6,
        lotNumber: 'ACME-001',
        expiryMonth: '2027-06',
        receiver: $receiver,
    );

    expect($lot->fresh()->received_quantity)->toBe(10)
        ->and($lot->fresh()->quantity_on_hand)->toBe(10)
        ->and($variant->fresh()->stock_quantity)->toBe(10)
        ->and(InventoryLot::query()->count())->toBe(1);
});

test('a repeated lot receipt rejects a conflicting expiry month atomically', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create(['stock_quantity' => 4]);
    $receiver = User::factory()->staff()->create();
    $lot = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'ACME-001',
        'expires_on' => '2027-06-30',
        'received_quantity' => 4,
        'quantity_on_hand' => 4,
    ]);

    expect(fn () => app(ReceiveContactLensStock::class)->handle(
        variant: $variant,
        quantity: 6,
        lotNumber: 'ACME-001',
        expiryMonth: '2027-07',
        receiver: $receiver,
    ))->toThrow(ValidationException::class);

    expect($lot->fresh()->received_quantity)->toBe(4)
        ->and($lot->fresh()->quantity_on_hand)->toBe(4)
        ->and($variant->fresh()->stock_quantity)->toBe(4)
        ->and(InventoryLot::query()->count())->toBe(1);
});

test('receiving rejects invalid or already expired months', function (string $expiryMonth) {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create(['stock_quantity' => 0]);
    $receiver = User::factory()->staff()->create();

    expect(fn () => app(ReceiveContactLensStock::class)->handle(
        variant: $variant,
        quantity: 1,
        lotNumber: 'ACME-001',
        expiryMonth: $expiryMonth,
        receiver: $receiver,
    ))->toThrow(ValidationException::class);

    expect(InventoryLot::query()->count())->toBe(0)
        ->and($variant->fresh()->stock_quantity)->toBe(0);
})->with([
    'malformed month' => '2027-13',
    'day precision' => '2027-06-30',
    'expired month' => '2026-07',
]);

test('the contact lens receiving action rejects non-contact variants', function () {
    $variant = ProductVariant::factory()->create();
    $receiver = User::factory()->staff()->create();

    expect(fn () => app(ReceiveContactLensStock::class)->handle(
        variant: $variant,
        quantity: 1,
        lotNumber: 'ACME-001',
        expiryMonth: '2027-06',
        receiver: $receiver,
    ))->toThrow(ValidationException::class);
});

test('the contact lens receiving action requires a panel role', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create(['stock_quantity' => 0]);
    $patient = User::factory()->patient()->create();

    expect(fn () => app(ReceiveContactLensStock::class)->handle(
        variant: $variant,
        quantity: 1,
        lotNumber: 'ACME-001',
        expiryMonth: '2027-06',
        receiver: $patient,
    ))->toThrow(AuthorizationException::class);
});
