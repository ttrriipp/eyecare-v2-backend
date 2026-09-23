<?php

use App\Models\InventoryLot;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

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

test('contact lens opening lots use eight digit numeric codes and migrate legacy lot numbers', function (): void {
    Storage::fake('public');
    User::factory()->staff()->create();

    $this->seed(CatalogSeeder::class);

    $contactLensVariants = ProductVariant::query()
        ->contactLenses()
        ->orderBy('sku')
        ->get();
    $legacyLotNumbers = [];

    foreach ($contactLensVariants as $variant) {
        $seededLotNumber = InventoryLot::query()
            ->whereBelongsTo($variant, 'variant')
            ->sole()
            ->lot_number;

        expect($seededLotNumber)->toMatch('/\A[0-9]{8}\z/');

        $legacyLotNumber = 'CL-ALCON-AOC2-'.strtoupper(str_replace(' ', '-', $variant->name)).'-202609';
        $legacyLotNumbers[$variant->sku] = $legacyLotNumber;

        InventoryLot::query()
            ->whereBelongsTo($variant, 'variant')
            ->sole()
            ->update(['lot_number' => $legacyLotNumber]);
    }

    $initialLotCount = InventoryLot::query()->count();
    $this->seed(CatalogSeeder::class);

    $migratedLotNumbers = [];

    foreach ($contactLensVariants as $variant) {
        $lotNumber = InventoryLot::query()
            ->whereBelongsTo($variant, 'variant')
            ->sole()
            ->lot_number;

        expect($lotNumber)
            ->toMatch('/\A[0-9]{8}\z/')
            ->not->toBe($legacyLotNumbers[$variant->sku]);

        $migratedLotNumbers[$variant->sku] = $lotNumber;
    }

    expect(InventoryLot::query()->count())->toBe($initialLotCount);

    $this->seed(CatalogSeeder::class);

    $reseededLotNumbers = [];

    foreach ($contactLensVariants as $variant) {
        $reseededLotNumbers[$variant->sku] = InventoryLot::query()
            ->whereBelongsTo($variant, 'variant')
            ->sole()
            ->lot_number;
    }

    expect($reseededLotNumbers)->toBe($migratedLotNumbers)
        ->and(InventoryLot::query()->count())->toBe($initialLotCount);
});

test('accessory opening lots use random printed codes and remain stable when the catalog is reseeded', function (): void {
    Storage::fake('public');
    User::factory()->staff()->create();

    $expectedAccessoryLots = [
        'ACC-NEWLOOK-MPS-90ML' => [
            'previous_seeded_lot_number' => 'NL6K29A',
            'legacy_lot_number' => 'ACC-NEWLOOK-MPS-202609',
        ],
        'ACC-SYSTANE-COMPLETE-PF-10ML' => [
            'previous_seeded_lot_number' => 'S4C9L2',
            'legacy_lot_number' => 'ACC-SYSTANE-COMPLETE-202609',
        ],
        'ACC-SYSTANE-HYDRATION-PF-10ML' => [
            'previous_seeded_lot_number' => 'H8D3M5',
            'legacy_lot_number' => 'ACC-SYSTANE-HYDRATION-202609',
        ],
        'ACC-SYSTANE-ULTRA-PF-10ML' => [
            'previous_seeded_lot_number' => '17XD9U',
            'legacy_lot_number' => 'ACC-SYSTANE-ULTRA-202609',
        ],
        'ACC-LACRYL-HYDRATE-10ML' => [
            'previous_seeded_lot_number' => 'L3K9H6',
            'legacy_lot_number' => 'ACC-LACRYL-HYDRATE-202609',
        ],
    ];
    $readAccessoryLotNumbers = function () use ($expectedAccessoryLots): array {
        $lotNumbers = [];

        foreach (array_keys($expectedAccessoryLots) as $sku) {
            $variant = ProductVariant::query()->where('sku', $sku)->firstOrFail();
            $lotNumbers[$sku] = InventoryLot::query()
                ->whereBelongsTo($variant, 'variant')
                ->sole()
                ->lot_number;
        }

        return $lotNumbers;
    };
    $assertAccessoryLotNumberFormat = function (array $lotNumbers): void {
        foreach ($lotNumbers as $lotNumber) {
            expect($lotNumber)->toMatch('/\A[0-9]{2}[A-Z]{2}[0-9][A-Z]\z/');
        }
    };
    $replaceAccessoryLotNumbers = function (string $lotNumberKey) use ($expectedAccessoryLots): void {
        foreach ($expectedAccessoryLots as $sku => $lotNumbers) {
            $variant = ProductVariant::query()->where('sku', $sku)->firstOrFail();
            $lot = InventoryLot::query()->whereBelongsTo($variant, 'variant')->sole();

            $lot->update(['lot_number' => $lotNumbers[$lotNumberKey]]);
        }
    };

    $this->seed(CatalogSeeder::class);

    $initialLotCount = InventoryLot::query()->count();
    $initialLotNumbers = $readAccessoryLotNumbers();
    $assertAccessoryLotNumberFormat($initialLotNumbers);

    $this->seed(CatalogSeeder::class);

    expect(InventoryLot::query()->count())->toBe($initialLotCount)
        ->and($readAccessoryLotNumbers())->toBe($initialLotNumbers);

    $replaceAccessoryLotNumbers('previous_seeded_lot_number');

    $this->seed(CatalogSeeder::class);

    $migratedExistingLotNumbers = $readAccessoryLotNumbers();
    $assertAccessoryLotNumberFormat($migratedExistingLotNumbers);

    foreach ($expectedAccessoryLots as $sku => $lotNumbers) {
        expect($migratedExistingLotNumbers[$sku])->not->toBe($lotNumbers['previous_seeded_lot_number']);
    }

    expect(InventoryLot::query()->count())->toBe($initialLotCount);

    $replaceAccessoryLotNumbers('legacy_lot_number');

    $this->seed(CatalogSeeder::class);

    $migratedLegacyLotNumbers = $readAccessoryLotNumbers();
    $assertAccessoryLotNumberFormat($migratedLegacyLotNumbers);

    $this->seed(CatalogSeeder::class);

    expect(InventoryLot::query()->count())->toBe($initialLotCount)
        ->and($readAccessoryLotNumbers())->toBe($migratedLegacyLotNumbers);
});
