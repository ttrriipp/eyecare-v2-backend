<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\LensType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
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
        'attributes' => ['lens_width' => 52, 'bridge' => 18, 'temple' => 140],
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
        ->and((new ProductVariant)->product())->toBeInstanceOf(BelongsTo::class);
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
        ->and(ProductVariant::query()->where('ar_eligible', true)->count())->toBeGreaterThanOrEqual(1);
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

test('product_variants table has attributes column not dimensions', function () {
    $columns = Schema::getColumnListing('product_variants');

    expect($columns)->toContain('attributes')
        ->and($columns)->not->toContain('dimensions');
});

test('product_type accepts contact_lens value', function () {
    $product = Product::factory()->create(['product_type' => 'contact_lens']);

    expect($product->product_type)->toBe('contact_lens');
});

test('variant attributes stores contact lens metadata', function () {
    $variant = ProductVariant::factory()->create([
        'attributes' => ['power' => '-1.25', 'base_curve' => '8.4', 'diameter' => '14.0'],
    ]);

    expect($variant->attributes)->toBe(['power' => '-1.25', 'base_curve' => '8.4', 'diameter' => '14.0']);
});

test('api returns attributes in variant response', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create();
    ProductVariant::factory()->for($product)->create([
        'attributes' => ['eye_size' => 52, 'bridge' => 18],
        'is_active' => true,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$product->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.variants.0.attributes.eye_size', 52);
});
