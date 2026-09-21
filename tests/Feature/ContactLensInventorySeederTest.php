<?php

use App\Models\InventoryLot;
use App\Models\ProductVariant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('canonical seed data includes inventory lots for expiry-tracked products', function (): void {
    $this->seed(DatabaseSeeder::class);

    $variants = ProductVariant::query()
        ->expiryTracked()
        ->with(['inventoryLots', 'inventoryMovements'])
        ->orderBy('sku')
        ->get();

    expect($variants)->toHaveCount(17);

    $variants->each(function (ProductVariant $variant): void {
        expect($variant->inventoryLots)->toHaveCount(1)
            ->and($variant->inventoryMovements)->toHaveCount(1)
            ->and($variant->stock_quantity)->toBeGreaterThan(0)
            ->and($variant->inventoryLots->sum('quantity_on_hand'))->toBe($variant->stock_quantity)
            ->and($variant->inventoryMovements->sum('quantity_change'))->toBe($variant->stock_quantity)
            ->and($variant->inventoryLots->first()->expires_on)->not->toBeNull();

        if ($variant->isContactLens()) {
            expect($variant->attributes)->toHaveKeys(['base_curve', 'diameter', 'color', 'pack_size']);

            return;
        }

        expect($variant->attributes)->toHaveKeys(['volume_ml', 'package_size', 'material']);
    });

    expect(InventoryLot::query()
        ->whereIn('product_variant_id', $variants->modelKeys())
        ->count())->toBe(17);
});

test('canonical contact lens inventory is idempotent when the database is reseeded', function (): void {
    $this->seed(DatabaseSeeder::class);

    $initialLots = InventoryLot::query()->count();
    $initialVariants = ProductVariant::query()->contactLenses()->count();
    $initialStocks = ProductVariant::query()->contactLenses()->pluck('stock_quantity', 'sku');

    $this->seed(DatabaseSeeder::class);

    expect(InventoryLot::query()->count())->toBe($initialLots)
        ->and($initialLots)->toBeGreaterThan(0)
        ->and(ProductVariant::query()->contactLenses()->count())->toBe($initialVariants)
        ->and(ProductVariant::query()->contactLenses()->pluck('stock_quantity', 'sku')->all())
        ->toBe($initialStocks->all());
});
