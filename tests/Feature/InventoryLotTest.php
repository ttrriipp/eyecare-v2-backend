<?php

use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['inventory.contact_lens_expiry_warning_days' => 90]));

afterEach(fn () => Carbon::setTestNow());

test('inventory lots cast dates and expose their related variant and receiver', function () {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create();
    $receiver = User::factory()->create();
    $receivedAt = Carbon::parse('2026-08-28 09:30:00');

    $lot = InventoryLot::factory()
        ->for($variant, 'variant')
        ->for($receiver, 'receivedBy')
        ->create([
            'expires_on' => '2027-02-28',
            'received_at' => $receivedAt,
        ]);

    expect($lot->expires_on)->toBeInstanceOf(Carbon::class)
        ->and($lot->expires_on->toDateString())->toBe('2027-02-28')
        ->and($lot->received_at)->toBeInstanceOf(Carbon::class)
        ->and($lot->received_at->toDateTimeString())->toBe($receivedAt->toDateTimeString())
        ->and($lot->variant->is($variant))->toBeTrue()
        ->and($lot->receivedBy->is($receiver))->toBeTrue();
});

test('inventory lot date scopes include the expiry date through the end of the day', function () {
    Carbon::setTestNow('2026-08-28 14:00:00');
    $variant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create();

    $today = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'TODAY',
        'expires_on' => '2026-08-28',
        'quantity_on_hand' => 3,
    ]);
    $soon = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'SOON',
        'expires_on' => '2026-11-26',
        'quantity_on_hand' => 2,
    ]);
    $afterWindow = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'LATER',
        'expires_on' => '2026-11-27',
        'quantity_on_hand' => 2,
    ]);
    $expired = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'EXPIRED',
        'expires_on' => '2026-08-27',
        'quantity_on_hand' => 2,
    ]);
    $empty = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'EMPTY',
        'expires_on' => '2026-09-10',
        'quantity_on_hand' => 0,
    ]);

    expect(InventoryLot::query()->notExpired()->pluck('id')->all())
        ->toEqualCanonicalizing([$today->id, $soon->id, $afterWindow->id, $empty->id])
        ->and(InventoryLot::query()->expired()->pluck('id')->all())
        ->toEqual([$expired->id])
        ->and(InventoryLot::query()->available()->pluck('id')->all())
        ->toEqualCanonicalizing([$today->id, $soon->id, $afterWindow->id, $expired->id])
        ->and(InventoryLot::query()->expiringSoon()->pluck('id')->all())
        ->toEqualCanonicalizing([$today->id, $soon->id]);
});

test('a lot expiring today remains available while an earlier lot is expired', function () {
    Carbon::setTestNow('2026-08-28 14:00:00');

    $today = InventoryLot::factory()->create([
        'expires_on' => '2026-08-28',
        'quantity_on_hand' => 1,
    ]);
    $expired = InventoryLot::factory()->create([
        'expires_on' => '2026-08-27',
        'quantity_on_hand' => 1,
    ]);

    expect($today->isExpired())->toBeFalse()
        ->and($today->isAvailable())->toBeTrue()
        ->and($expired->isExpired())->toBeTrue()
        ->and($expired->isAvailable())->toBeFalse();
});

test('the expiring-soon window uses the inventory configuration by default', function () {
    Carbon::setTestNow('2026-08-28 14:00:00');
    config(['inventory.contact_lens_expiry_warning_days' => 0]);

    $today = InventoryLot::factory()->create([
        'expires_on' => '2026-08-28',
    ]);
    $tomorrow = InventoryLot::factory()->create([
        'expires_on' => '2026-08-29',
    ]);

    expect(InventoryLot::query()->expiringSoon()->pluck('id')->all())
        ->toEqual([$today->id])
        ->and(InventoryLot::query()->expiringSoon(1)->pluck('id')->all())
        ->toEqualCanonicalizing([$today->id, $tomorrow->id]);
});
