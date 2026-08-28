<?php

use App\Models\InventoryLot;
use App\Models\ProductVariant;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

test('canonical seed data gives every contact lens variant lot-backed stock', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-28 09:00:00', 'Asia/Manila'));

    $this->seed(DatabaseSeeder::class);

    $variants = ProductVariant::query()
        ->contactLenses()
        ->with('inventoryLots')
        ->orderBy('sku')
        ->get();

    expect($variants)->toHaveCount(2);

    $variants->each(function (ProductVariant $variant): void {
        expect($variant->inventoryLots)->not->toBeEmpty()
            ->and($variant->stock_quantity)
            ->toBe($variant->inventoryLots->sum('quantity_on_hand'))
            ->and($variant->inventoryLots->every(
                fn (InventoryLot $lot): bool => $lot->received_quantity >= $lot->quantity_on_hand,
            ))->toBeTrue();
    });

    expect(InventoryLot::query()->expiringSoon(90, Carbon::now())->count())->toBe(2)
        ->and(InventoryLot::query()
            ->whereDate('expires_on', '>', Carbon::now()->addDays(90)->toDateString())
            ->count())->toBe(2)
        ->and(InventoryLot::query()->count())->toBe(4)
        ->and(InventoryLot::query()->whereHas(
            'receivedBy',
            fn (Builder $query): Builder => $query->where('email', 'staff@eyecare.test'),
        )->count())
        ->toBe(4);
});

test('canonical contact lens lots are idempotent when the database is reseeded', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-28 09:00:00', 'Asia/Manila'));

    $this->seed(DatabaseSeeder::class);

    $initialLots = InventoryLot::query()->count();
    $initialStocks = ProductVariant::query()->contactLenses()->pluck('stock_quantity', 'sku');

    $this->seed(DatabaseSeeder::class);

    expect(InventoryLot::query()->count())->toBe($initialLots)
        ->and(ProductVariant::query()->contactLenses()->pluck('stock_quantity', 'sku')->all())
        ->toBe($initialStocks->all());
});
