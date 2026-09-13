<?php

use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
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

    $approvedProductSlugs = [
        'sofia-2860',
        'mormaii-floater-street-280',
        'anthos-mb-1399-a',
        'cest-joli-2860',
        'black-red-sports-optical-frame',
        'new-look-multi-purpose-all-in-one-solution',
        'systane-complete-preservative-free-lubricant-eye-drops',
        'systane-hydration-preservative-free-lubricant-eye-drops',
        'systane-ultra-preservative-free-lubricant-eye-drops',
        'lacryl-hydrate-eye-drops',
        'air-optix-colors',
        'model-8763-optical-frame',
        'nike-5753-optical-frame',
        'ginos-collection-13978-optical-frame',
        'sofia-eyewear-52103-optical-frame',
        'polo-fashion-p002-optical-frame',
    ];

    $initialProductCount = Product::query()->count();
    $initialVariantCount = ProductVariant::query()->count();

    $approvedProducts = Product::query()->whereIn('slug', $approvedProductSlugs);

    expect((clone $approvedProducts)->where('is_active', true)->where('product_type', 'frame')->count())->toBe(10)
        ->and((clone $approvedProducts)->where('is_active', true)->where('product_type', 'accessory')->count())->toBe(5)
        ->and((clone $approvedProducts)->where('is_active', true)->where('product_type', 'contact_lens')->count())->toBe(1);

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

test('catalog seeder adds the new frames with their organized variant images', function (): void {
    Storage::fake('public');

    $this->seed(CatalogSeeder::class);

    $newFrames = [
        'model-8763-optical-frame' => [
            'brand' => 'Unknown',
            'sku' => 'FRAME-8763-C2',
            'images' => ['01-front.png', '02-side.png'],
            'attributes' => [
                'color' => 'Black / Gold',
                'lens_width' => 54,
                'bridge' => 18,
                'temple' => 150,
                'model_code' => '8763',
            ],
        ],
        'nike-5753-optical-frame' => [
            'brand' => 'Nike',
            'sku' => 'NIKE-5753-BLK',
            'images' => ['01-front.jpg', '02-side.jpg'],
            'attributes' => [
                'color' => 'Black',
                'lens_width' => 49,
                'bridge' => 21,
                'temple' => 145,
                'model_code' => '5753',
            ],
        ],
        'ginos-collection-13978-optical-frame' => [
            'brand' => "Gino's Collection",
            'sku' => 'GINOS-13978-BRG',
            'images' => ['01-front.png', '02-side.png'],
            'attributes' => [
                'color' => 'Burgundy / Red',
                'lens_width' => 53,
                'bridge' => 18,
                'temple' => 143,
                'model_code' => '13978',
            ],
        ],
        'sofia-eyewear-52103-optical-frame' => [
            'brand' => 'SOFIA EYEWEAR',
            'sku' => 'SOFIA-52103-C7',
            'images' => ['01-front.png', '02-side.png'],
            'attributes' => [
                'color' => 'Clear / Transparent',
                'lens_width' => 56,
                'bridge' => 17,
                'temple' => 148,
                'model_code' => '52103',
                'color_code' => 'C7',
            ],
        ],
        'polo-fashion-p002-optical-frame' => [
            'brand' => 'Polo Fashion',
            'sku' => 'POLO-P002-DGM',
            'images' => ['01-front.png', '02-side.png'],
            'attributes' => [
                'color' => 'Dark Gunmetal / Black',
                'material' => 'Metal',
                'lens_width' => 53,
                'bridge' => 18,
                'temple' => 142,
                'model_code' => 'P002',
            ],
        ],
    ];

    foreach ($newFrames as $slug => $expected) {
        $product = Product::query()
            ->with(['brand', 'category', 'variants'])
            ->where('slug', $slug)
            ->firstOrFail();
        $variant = $product->variants->sole();
        $expectedImages = collect($expected['images'])
            ->map(fn (string $filename): string => "variants/{$expected['sku']}/{$filename}")
            ->all();

        expect($product->product_type)->toBe('frame')
            ->and($product->brand?->name)->toBe($expected['brand'])
            ->and($product->category?->name)->toBe('Optical Frame')
            ->and($variant->attributes)->toMatchArray($expected['attributes'])
            ->and($variant->price)->toBeGreaterThan(0)
            ->and($variant->images)->toBe($expectedImages)
            ->and($product->images)->toBe($expectedImages)
            ->and(Storage::disk('public')->allFiles("variants/{$expected['sku']}"))->toHaveCount(2);
    }
});

test('catalog seeder fails when a seeded image cannot be written to catalog storage', function (): void {
    $catalogDisk = config('filesystems.catalog_disk');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->atLeast()->once()->andReturn(false);
    Storage::shouldReceive('disk')->with($catalogDisk)->andReturn($disk);

    expect(fn () => $this->seed(CatalogSeeder::class))
        ->toThrow(RuntimeException::class, 'Unable to write seeded catalog image');
});

test('seeded frame materials use short labels for the mobile catalog', function (): void {
    $this->seed(CatalogSeeder::class);

    $frameMaterials = ProductVariant::query()
        ->whereIn('sku', [
            'FRM-SOFIA-2860-GRY',
            'FRM-SOFIA-2860-CHAMP',
            'SUN-MORMAII-FLOATER280-BLK',
            'FRM-ANTHOS-MB1399A-C4',
            'FRM-CESTJOLI-2860-C4',
            'FRM-SPORT-BLKRED-001',
        ])
        ->whereHas('product', fn (Builder $query): Builder => $query->where('product_type', 'frame'))
        ->get()
        ->pluck('attributes.material', 'sku')
        ->all();

    expect($frameMaterials)->toMatchArray([
        'FRM-SOFIA-2860-GRY' => 'TR90',
        'FRM-SOFIA-2860-CHAMP' => 'TR90',
        'SUN-MORMAII-FLOATER280-BLK' => 'Plastic',
        'FRM-ANTHOS-MB1399A-C4' => 'Plastic',
        'FRM-CESTJOLI-2860-C4' => 'Metal',
        'FRM-SPORT-BLKRED-001' => 'Plastic',
    ]);
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
