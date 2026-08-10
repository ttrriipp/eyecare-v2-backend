<?php

use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\Product;
use App\Models\ProductCategory;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('product management exposes only physical frame, contact lens, and accessory types', function (): void {
    expect(Product::TYPE_OPTIONS)->toBe([
        'frame' => 'Frame',
        'contact_lens' => 'Contact Lens',
        'accessory' => 'Accessory',
    ])->not->toHaveKey('lens');
});

test('product factory states cover the supported physical product types', function (): void {
    expect(Product::factory()->make()->product_type)->toBe('frame')
        ->and(Product::factory()->contactLens()->make()->product_type)->toBe('contact_lens')
        ->and(Product::factory()->accessory()->make()->product_type)->toBe('accessory');
});

test('catalog seeder represents prescription lenses in separate catalogs', function (): void {
    $this->seed(CatalogSeeder::class);

    expect(Product::query()->where('product_type', 'lens')->count())->toBe(0)
        ->and(LensCategory::query()->whereIn('name', [
            'Essilor Varilux Progressive 1.67',
            'Zeiss Single Vision 1.50',
        ])->count())->toBe(2)
        ->and(ProductCategory::query()->where('name', 'Lenses')->exists())->toBeFalse()
        ->and(LensOption::query()->where('name', 'Anti-Reflective')->exists())->toBeTrue();

    $contactLens = Product::query()
        ->where('name', 'Acuvue Oasys')
        ->where('product_type', 'contact_lens')
        ->first();

    expect($contactLens)->not->toBeNull()
        ->and(ProductCategory::query()->where('name', 'Contact Lenses')->exists())->toBeTrue()
        ->and($contactLens->variants)->not->toBeEmpty()
        ->and($contactLens->variants->first()->attributes)->toMatchArray([
            'power' => '-2.00',
            'base_curve' => 8.4,
            'diameter' => 14.0,
            'cylinder' => null,
            'axis' => null,
            'add' => null,
            'color' => null,
            'pack_size' => 6,
        ]);
});

test('legacy lens products are deactivated without losing their compatibility reference', function (): void {
    $lensCategory = LensCategory::factory()->withPrice()->create();
    $legacyProduct = Product::factory()->create([
        'product_type' => 'lens',
        'is_active' => true,
        'lens_category_id' => $lensCategory->id,
    ]);

    $migrationPaths = glob(database_path('migrations/*deactivate_legacy_lens_products.php'));
    expect($migrationPaths)->toHaveCount(1);

    $migration = require $migrationPaths[0];
    $migration->up();

    expect($legacyProduct->fresh()->is_active)->toBeFalse()
        ->and($legacyProduct->fresh()->lens_category_id)->toBe($lensCategory->id);
});
