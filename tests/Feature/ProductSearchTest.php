<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->customer = User::factory()->customer()->create();
});

// ─── Backwards compatibility ──────────────────────────────────────────────────

test('GET /products without params returns all visible mobile catalog products paginated', function () {
    Product::factory()
        ->accessory()
        ->has(ProductVariant::factory(), 'variants')
        ->count(3)
        ->create(['is_active' => true]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/products')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

// ─── Search ───────────────────────────────────────────────────────────────────

test('search param filters by product name', function () {
    Product::factory()->accessory()->has(ProductVariant::factory(), 'variants')->create([
        'name' => 'Classic Case',
        'is_active' => true,
    ]);
    Product::factory()->accessory()->has(ProductVariant::factory(), 'variants')->create([
        'name' => 'Cleaning Cloth',
        'is_active' => true,
    ]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/products?search=classic')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Classic Case');
});

test('search param filters by product description', function () {
    Product::factory()->accessory()->has(ProductVariant::factory(), 'variants')->create([
        'name' => 'Accessory A',
        'description' => 'lightweight titanium',
        'is_active' => true,
    ]);
    Product::factory()->accessory()->has(ProductVariant::factory(), 'variants')->create([
        'name' => 'Accessory B',
        'description' => 'soft cleaning cloth',
        'is_active' => true,
    ]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/products?search=titanium')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ─── Brand filter ─────────────────────────────────────────────────────────────

test('brand param filters by brand', function () {
    $brand = Brand::factory()->create();
    $otherBrand = Brand::factory()->create();

    Product::factory()->accessory()->has(ProductVariant::factory(), 'variants')->create([
        'brand_id' => $brand->id,
        'is_active' => true,
    ]);
    Product::factory()->accessory()->has(ProductVariant::factory(), 'variants')->create([
        'brand_id' => $otherBrand->id,
        'is_active' => true,
    ]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson("/api/products?brand={$brand->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ─── Category filter ──────────────────────────────────────────────────────────

test('category param filters by category', function () {
    $category = ProductCategory::factory()->create();

    Product::factory()->accessory()->has(ProductVariant::factory(), 'variants')->create([
        'category_id' => $category->id,
        'is_active' => true,
    ]);
    Product::factory()->accessory()->has(ProductVariant::factory(), 'variants')->create(['is_active' => true]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson("/api/products?category={$category->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ─── Price range ──────────────────────────────────────────────────────────────

test('min_price param filters products with a variant at or above the price', function () {
    $cheapProduct = Product::factory()->create(['is_active' => true]);
    ProductVariant::factory()->for($cheapProduct)->arEligible()->create(['price' => 50]);
    ProductVariant::factory()->for($cheapProduct)->create(['price' => 500]);

    $expensiveProduct = Product::factory()->accessory()->create(['is_active' => true]);
    ProductVariant::factory()->for($expensiveProduct)->create(['price' => 500]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/products?min_price=200')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $expensiveProduct->id);
});

test('max_price param filters products with a variant at or below the price', function () {
    $cheapProduct = Product::factory()->accessory()->create(['is_active' => true]);
    ProductVariant::factory()->for($cheapProduct)->create(['price' => 50]);

    $expensiveProduct = Product::factory()->create(['is_active' => true]);
    ProductVariant::factory()->for($expensiveProduct)->arEligible()->create(['price' => 500]);
    ProductVariant::factory()->for($expensiveProduct)->create(['price' => 50]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/products?max_price=100')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $cheapProduct->id);
});

// ─── In-stock filter ──────────────────────────────────────────────────────────

test('in_stock param returns only products with stock', function () {
    $inStockProduct = Product::factory()->accessory()->create(['is_active' => true]);
    ProductVariant::factory()->for($inStockProduct)->create(['stock_quantity' => 5]);

    $outOfStockProduct = Product::factory()->create(['is_active' => true]);
    ProductVariant::factory()->for($outOfStockProduct)->arEligible()->create(['stock_quantity' => 0]);
    ProductVariant::factory()->for($outOfStockProduct)->create(['stock_quantity' => 5]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/products?in_stock=true')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inStockProduct->id);
});

// ─── Sort ─────────────────────────────────────────────────────────────────────

test('sort=name orders products alphabetically', function () {
    Product::factory()->accessory()->has(ProductVariant::factory(), 'variants')->create([
        'name' => 'Zebra Case',
        'is_active' => true,
    ]);
    Product::factory()->accessory()->has(ProductVariant::factory(), 'variants')->create([
        'name' => 'Alpha Cloth',
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/products?sort=name')
        ->assertOk();

    expect($response->json('data.0.name'))->toBe('Alpha Cloth');
});

test('sort=newest orders products by creation date descending', function () {
    Product::factory()->accessory()->has(ProductVariant::factory(), 'variants')->create([
        'is_active' => true,
        'created_at' => now()->subDays(5),
    ]);
    $new = Product::factory()->accessory()->has(ProductVariant::factory(), 'variants')->create([
        'is_active' => true,
        'created_at' => now(),
    ]);

    $response = $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/products?sort=newest')
        ->assertOk();

    expect($response->json('data.0.id'))->toBe($new->id);
});

test('sort=price_asc orders by cheapest variant first', function () {
    $expensiveProduct = Product::factory()->create(['is_active' => true]);
    ProductVariant::factory()->for($expensiveProduct)->arEligible()->create(['price' => 500]);
    ProductVariant::factory()->for($expensiveProduct)->create(['price' => 10]);

    $cheapProduct = Product::factory()->accessory()->create(['is_active' => true]);
    ProductVariant::factory()->for($cheapProduct)->create(['price' => 50]);

    $response = $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/products?sort=price_asc')
        ->assertOk();

    expect($response->json('data.0.id'))->toBe($cheapProduct->id);
});

// ─── Combined filters ─────────────────────────────────────────────────────────

test('multiple filters combine correctly', function () {
    $brand = Brand::factory()->create();

    $match = Product::factory()->accessory()->create([
        'name' => 'Classic Case',
        'brand_id' => $brand->id,
        'is_active' => true,
    ]);
    ProductVariant::factory()->create(['product_id' => $match->id, 'stock_quantity' => 5]);

    $noMatch = Product::factory()->accessory()->create([
        'name' => 'Classic Cloth',
        'is_active' => true,
    ]);
    ProductVariant::factory()->create(['product_id' => $noMatch->id, 'stock_quantity' => 0]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson("/api/products?search=classic&brand={$brand->id}&in_stock=true")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});
