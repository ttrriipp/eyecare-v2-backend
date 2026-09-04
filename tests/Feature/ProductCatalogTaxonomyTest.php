<?php

use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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

test('catalog seeder provides a complete active lens option catalog', function (): void {
    Storage::fake('public');

    $this->seed(CatalogSeeder::class);

    $expectedOptions = [
        'Anti-Reflective' => 1000.00,
        'Photochromic Treatment' => 2500.00,
        'Polarized Lens Treatment' => 1800.00,
        'Scratch-Resistant Coating' => 500.00,
        'Tinted Lens Treatment' => 700.00,
        'UV Protection' => 400.00,
    ];

    $activeOptions = LensOption::query()
        ->active()
        ->orderBy('name')
        ->get()
        ->keyBy('name');

    expect($activeOptions->keys()->all())->toBe(array_keys($expectedOptions));

    foreach ($expectedOptions as $name => $price) {
        $option = $activeOptions->get($name);

        expect($option)->not->toBeNull()
            ->and((float) $option?->price)->toEqualWithDelta($price, 0.001)
            ->and($option?->description)->not->toBeEmpty();
    }

    expect(LensOption::query()->where('name', 'Blue Light Filter (Discontinued)')->value('is_active'))
        ->toBeFalse();
});

test('catalog seeder imports the approved clinic product catalog idempotently', function (): void {
    Storage::fake('public');

    $this->seed(CatalogSeeder::class);

    $initialProductCount = Product::query()->count();
    $initialVariantCount = ProductVariant::query()->count();

    expect(Product::query()->where('is_active', true)->where('product_type', 'frame')->count())->toBe(5)
        ->and(Product::query()->where('is_active', true)->where('product_type', 'accessory')->count())->toBe(5)
        ->and(Product::query()->where('is_active', true)->where('product_type', 'contact_lens')->count())->toBe(1);

    $mormaii = Product::query()->where('slug', 'mormaii-floater-street-280')->firstOrFail();

    expect($mormaii->product_type)->toBe('frame')
        ->and($mormaii->category?->name)->toBe('Sports Sunglasses')
        ->and($mormaii->variants)->toHaveCount(1)
        ->and($mormaii->variants->first()->sku)->toBe('SUN-MORMAII-FLOATER280-BLK');

    $contactLens = Product::query()
        ->where('slug', 'air-optix-colors')
        ->where('product_type', 'contact_lens')
        ->firstOrFail();

    expect($contactLens->variants)->toHaveCount(12)
        ->and($contactLens->variants->pluck('sku')->unique())->toHaveCount(12)
        ->and($contactLens->images)->toBe([
            'products/air-optix-colors/01-packaging.png',
            'products/air-optix-colors/02-color-guide.jpg',
            'products/air-optix-colors/03-color-swatches.png',
            'products/air-optix-colors/04-lens-pair.png',
        ])
        ->and($contactLens->variants->firstWhere('sku', 'CL-ALCON-AOC2-BROWN')?->images)
        ->toBe(['variants/CL-ALCON-AOC2-BROWN/01-color.png'])
        ->and($contactLens->variants->firstWhere('sku', 'CL-ALCON-AOC2-PURE-HAZEL')?->images)
        ->toBe(['variants/CL-ALCON-AOC2-PURE-HAZEL/01-color.png'])
        ->and($contactLens->variants->every(fn (ProductVariant $variant): bool => $variant->stock_quantity === 0))->toBeTrue()
        ->and($contactLens->variants->every(fn (ProductVariant $variant): bool => $variant->inventoryLots->isEmpty()))->toBeTrue();

    $sofiaGray = ProductVariant::query()->where('sku', 'FRM-SOFIA-2860-GRY')->firstOrFail();
    $sofiaChampagne = ProductVariant::query()->where('sku', 'FRM-SOFIA-2860-CHAMP')->firstOrFail();

    expect($sofiaGray->images)->toBe([
        'variants/FRM-SOFIA-2860-GRY/01-front.png',
        'variants/FRM-SOFIA-2860-GRY/02-side.png',
    ])
        ->and($sofiaChampagne->images)->toBe([
            'variants/FRM-SOFIA-2860-CHAMP/01-front.png',
            'variants/FRM-SOFIA-2860-CHAMP/02-side.png',
        ])
        ->and(Storage::disk('public')->allFiles('products/air-optix-colors'))->toHaveCount(4)
        ->and(Storage::disk('public')->allFiles('variants/FRM-SOFIA-2860-GRY'))->toHaveCount(2);

    $importedVariants = ProductVariant::query()
        ->whereHas('product', fn (Builder $query): Builder => $query->where('is_active', true))
        ->get();
    $importedProducts = Product::query()->where('is_active', true)->get();

    expect($importedProducts->every(fn (Product $product): bool => $product->images !== []))->toBeTrue()
        ->and($importedVariants->every(fn (ProductVariant $variant): bool => $variant->images !== []))->toBeTrue()
        ->and($importedVariants->every(fn (ProductVariant $variant): bool => $variant->price > 0))->toBeTrue();

    $this->seed(CatalogSeeder::class);

    expect(Product::query()->count())->toBe($initialProductCount)
        ->and(ProductVariant::query()->count())->toBe($initialVariantCount);

    expect(Product::query()->where('product_type', 'lens')->count())->toBe(0)
        ->and(LensCategory::query()->whereIn('name', [
            'Essilor Varilux Progressive 1.67',
            'Zeiss Single Vision 1.50',
        ])->count())->toBe(2)
        ->and(ProductCategory::query()->where('name', 'Lenses')->exists())->toBeFalse()
        ->and(LensOption::query()->where('name', 'Anti-Reflective')->exists())->toBeTrue();

    expect(ProductCategory::query()->where('name', 'Colored Contact Lens')->exists())->toBeTrue();
});

test('seeded frame materials stay concise for the mobile catalog', function (): void {
    $this->seed(CatalogSeeder::class);

    $sportsVariant = ProductVariant::query()
        ->where('sku', 'FRM-SPORT-BLKRED-001')
        ->firstOrFail();

    expect($sportsVariant->attributes['material'])->toBe('Plastic / rubber grips');
});

test('catalog seeder deactivates known demo products without deleting their records', function (): void {
    $legacyProduct = Product::factory()->create([
        'slug' => 'classic-rectangle-frame',
        'is_active' => true,
    ]);
    $legacyVariant = ProductVariant::factory()->create([
        'product_id' => $legacyProduct->id,
        'sku' => 'CRF-BLK-001',
        'is_active' => true,
    ]);

    $this->seed(CatalogSeeder::class);

    expect($legacyProduct->fresh())->not->toBeNull()
        ->and($legacyProduct->fresh()->is_active)->toBeFalse()
        ->and($legacyVariant->fresh())->not->toBeNull()
        ->and($legacyVariant->fresh()->is_active)->toBeFalse();
});

test('legacy lens product support is absent from the current schema', function (): void {
    expect(Schema::hasColumn('products', 'lens_category_id'))->toBeFalse()
        ->and(Product::query()->where('product_type', 'lens')->count())->toBe(0);
});
