<?php

use App\Models\InventoryLot;
use App\Models\ProductVariant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('canonical seed data leaves unreceived contact lenses without stock or lots', function (): void {
    $this->seed(DatabaseSeeder::class);

    $variants = ProductVariant::query()
        ->contactLenses()
        ->with('inventoryLots')
        ->orderBy('sku')
        ->get();

    expect($variants)->toHaveCount(12);

    $variants->each(function (ProductVariant $variant): void {
        expect($variant->inventoryLots)->toBeEmpty()
            ->and($variant->stock_quantity)->toBe(0)
            ->and($variant->attributes)->toHaveKeys(['base_curve', 'diameter', 'color', 'pack_size']);
    });

    expect(InventoryLot::query()->count())->toBe(0);
});

test('canonical contact lens inventory is idempotent when the database is reseeded', function (): void {
    $this->seed(DatabaseSeeder::class);

    $initialLots = InventoryLot::query()->count();
    $initialVariants = ProductVariant::query()->contactLenses()->count();
    $initialStocks = ProductVariant::query()->contactLenses()->pluck('stock_quantity', 'sku');

    $this->seed(DatabaseSeeder::class);

    expect(InventoryLot::query()->count())->toBe($initialLots)
        ->and(ProductVariant::query()->contactLenses()->count())->toBe($initialVariants)
        ->and(ProductVariant::query()->contactLenses()->pluck('stock_quantity', 'sku')->all())
        ->toBe($initialStocks->all());
});
