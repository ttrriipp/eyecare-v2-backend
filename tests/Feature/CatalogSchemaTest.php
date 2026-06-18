<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\LensType;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('product factory creates valid catalog records with required attributes', function () {
    $product = Product::factory()->create([
        'is_active' => true,
    ]);

    $variant = ProductVariant::factory()->for($product)->create([
        'is_active' => true,
        'price' => 219.99,
        'dimensions' => ['lens_width' => 52, 'bridge' => 18, 'temple' => 140],
        'stock_quantity' => 12,
        'low_stock_threshold' => 3,
        'ar_eligible' => true,
        'ar_asset_reference' => 'frames/demo-classic.glb',
    ]);

    expect($product->brand)->toBeInstanceOf(Brand::class)
        ->and($product->category)->toBeInstanceOf(Category::class)
        ->and($variant->product->is($product))->toBeTrue()
        ->and($variant->stock_quantity)->toBe(12)
        ->and($variant->low_stock_threshold)->toBe(3)
        ->and($variant->ar_asset_reference)->toBe('frames/demo-classic.glb');
});

test('catalog relationships are typed', function () {
    expect((new Product)->brand())->toBeInstanceOf(BelongsTo::class)
        ->and((new Product)->category())->toBeInstanceOf(BelongsTo::class)
        ->and((new Product)->variants())->toBeInstanceOf(HasMany::class)
        ->and((new Product)->images())->toBeInstanceOf(HasMany::class)
        ->and((new ProductVariant)->product())->toBeInstanceOf(BelongsTo::class)
        ->and((new ProductVariant)->images())->toBeInstanceOf(HasMany::class);
});

test('variant ar metadata columns exclude biometric fields', function () {
    $columns = Schema::getColumnListing('product_variants');

    expect($columns)->toContain('ar_eligible', 'ar_asset_reference')
        ->and($columns)->not->toContain('face_geometry', 'facial_landmarks', 'biometric_identifier', 'ar_analytics');
});

test('catalog seeder creates demo frame products and lens types idempotently', function () {
    $this->seed(CatalogSeeder::class);
    $this->seed(CatalogSeeder::class);

    expect(LensType::query()->pluck('name')->all())
        ->toEqualCanonicalizing([
            'single_vision',
            'progressive',
            'bifocal',
        ])
        ->and(Product::query()->where('is_active', true)->count())->toBeGreaterThanOrEqual(2)
        ->and(ProductVariant::query()->where('ar_eligible', true)->count())->toBeGreaterThanOrEqual(1)
        ->and(ProductImage::query()->count())->toBeGreaterThanOrEqual(1);
});

test('product slug auto-generates from name if not provided', function () {
    $brand = Brand::factory()->create();
    $category = Category::factory()->create();

    $product = Product::factory()->create([
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'name' => 'Classic Aviator Frame',
        'slug' => null,
    ]);

    expect($product->slug)->toBe('classic-aviator-frame');
});

test('product slug auto-generates with suffix on collision', function () {
    $brand = Brand::factory()->create();
    $category = Category::factory()->create();

    Product::factory()->create([
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'name' => 'Collision Frame',
        'slug' => 'collision-frame',
    ]);

    $second = Product::factory()->create([
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'name' => 'Collision Frame',
        'slug' => null,
    ]);

    expect($second->slug)->toBe('collision-frame-1');
});

test('product variant sku auto-generates as VAR-XXXXXX if not provided', function () {
    $variant = ProductVariant::factory()->create(['sku' => null]);

    expect($variant->sku)->toMatch('/^VAR-\d{6}$/');
});

test('product variant sku is preserved when explicitly provided', function () {
    $variant = ProductVariant::factory()->create(['sku' => 'CUSTOM-SKU-001']);

    expect($variant->sku)->toBe('CUSTOM-SKU-001');
});
