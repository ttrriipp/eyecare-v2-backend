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

test('GET /products without params returns all active frames paginated', function () {
    Product::factory()->count(3)->create(['product_type' => 'frame', 'is_active' => true]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/products')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

// ─── Search ───────────────────────────────────────────────────────────────────

test('search param filters by product name', function () {
    Product::factory()->create(['name' => 'Classic Rectangle', 'product_type' => 'frame', 'is_active' => true]);
    Product::factory()->create(['name' => 'Aviator Frame', 'product_type' => 'frame', 'is_active' => true]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/products?search=classic')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Classic Rectangle');
});

test('search param filters by product description', function () {
    Product::factory()->create([
        'name' => 'Frame A',
        'description' => 'lightweight titanium',
        'product_type' => 'frame',
        'is_active' => true,
    ]);
    Product::factory()->create([
        'name' => 'Frame B',
        'description' => 'acetate frame',
        'product_type' => 'frame',
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

    Product::factory()->create(['brand_id' => $brand->id, 'product_type' => 'frame', 'is_active' => true]);
    Product::factory()->create(['brand_id' => $otherBrand->id, 'product_type' => 'frame', 'is_active' => true]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson("/api/products?brand={$brand->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ─── Category filter ──────────────────────────────────────────────────────────

test('category param filters by category', function () {
    $category = ProductCategory::factory()->create();

    Product::factory()->create(['category_id' => $category->id, 'product_type' => 'frame', 'is_active' => true]);
    Product::factory()->create(['product_type' => 'frame', 'is_active' => true]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson("/api/products?category={$category->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ─── Price range ──────────────────────────────────────────────────────────────

test('min_price param filters products with a variant at or above the price', function () {
    $cheapProduct = Product::factory()->create(['product_type' => 'frame', 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $cheapProduct->id, 'price' => 50]);

    $expensiveProduct = Product::factory()->create(['product_type' => 'frame', 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $expensiveProduct->id, 'price' => 500]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/products?min_price=200')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $expensiveProduct->id);
});

test('max_price param filters products with a variant at or below the price', function () {
    $cheapProduct = Product::factory()->create(['product_type' => 'frame', 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $cheapProduct->id, 'price' => 50]);

    $expensiveProduct = Product::factory()->create(['product_type' => 'frame', 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $expensiveProduct->id, 'price' => 500]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/products?max_price=100')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $cheapProduct->id);
});

// ─── In-stock filter ──────────────────────────────────────────────────────────

test('in_stock param returns only products with stock', function () {
    $inStockProduct = Product::factory()->create(['product_type' => 'frame', 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $inStockProduct->id, 'stock_quantity' => 5]);

    $outOfStockProduct = Product::factory()->create(['product_type' => 'frame', 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $outOfStockProduct->id, 'stock_quantity' => 0]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/products?in_stock=true')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inStockProduct->id);
});

// ─── Sort ─────────────────────────────────────────────────────────────────────

test('sort=name orders products alphabetically', function () {
    Product::factory()->create(['name' => 'Zebra Frame', 'product_type' => 'frame', 'is_active' => true]);
    Product::factory()->create(['name' => 'Alpha Frame', 'product_type' => 'frame', 'is_active' => true]);

    $response = $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/products?sort=name')
        ->assertOk();

    expect($response->json('data.0.name'))->toBe('Alpha Frame');
});

test('sort=newest orders products by creation date descending', function () {
    $old = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'created_at' => now()->subDays(5)]);
    $new = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'created_at' => now()]);

    $response = $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/products?sort=newest')
        ->assertOk();

    expect($response->json('data.0.id'))->toBe($new->id);
});

test('sort=price_asc orders by cheapest variant first', function () {
    $expensiveProduct = Product::factory()->create(['product_type' => 'frame', 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $expensiveProduct->id, 'price' => 500]);

    $cheapProduct = Product::factory()->create(['product_type' => 'frame', 'is_active' => true]);
    ProductVariant::factory()->create(['product_id' => $cheapProduct->id, 'price' => 50]);

    $response = $this->actingAs($this->customer, 'sanctum')
        ->getJson('/api/products?sort=price_asc')
        ->assertOk();

    expect($response->json('data.0.id'))->toBe($cheapProduct->id);
});

// ─── Combined filters ─────────────────────────────────────────────────────────

test('multiple filters combine correctly', function () {
    $brand = Brand::factory()->create();

    $match = Product::factory()->create([
        'name' => 'Classic Lens',
        'brand_id' => $brand->id,
        'product_type' => 'frame',
        'is_active' => true,
    ]);
    ProductVariant::factory()->create(['product_id' => $match->id, 'stock_quantity' => 5]);

    $noMatch = Product::factory()->create([
        'name' => 'Classic Frame',
        'product_type' => 'frame',
        'is_active' => true,
    ]);
    ProductVariant::factory()->create(['product_id' => $noMatch->id, 'stock_quantity' => 0]);

    $this->actingAs($this->customer, 'sanctum')
        ->getJson("/api/products?search=classic&brand={$brand->id}&in_stock=true")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});
