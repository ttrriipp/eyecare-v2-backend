<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->brand = Brand::factory()->create();
});

test('mobile catalog returns active frames only', function () {
    $user = User::factory()->patient()->create();

    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'is_active' => true,
        'ar_eligible' => true,
        'ar_asset_reference' => 'ar-assets/test.glb',
    ]);

    $accessory = Product::factory()->create([
        'product_type' => 'accessory',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
    ProductVariant::factory()->create(['product_id' => $accessory->id, 'is_active' => true]);

    $this->actingAs($user)
        ->getJson('/api/v1/frames')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product_type', 'frame');
});

test('mobile catalog excludes accessories and lenses', function () {
    $user = User::factory()->patient()->create();

    $accessory = Product::factory()->create([
        'product_type' => 'accessory',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
    ProductVariant::factory()->create(['product_id' => $accessory->id, 'is_active' => true]);

    $lens = Product::factory()->create([
        'product_type' => 'lens',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/frames')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('mobile catalog excludes cost price and stock counts', function () {
    $user = User::factory()->patient()->create();

    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
    ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'is_active' => true,
        'ar_eligible' => true,
        'ar_asset_reference' => 'ar-assets/test.glb',
        'price' => 159.99,
        'cost_price' => 80.00,
        'stock_quantity' => 10,
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/frames')
        ->assertOk();

    $variant = $response->json('data.0.variants.0');
    expect($variant)->not->toHaveKey('cost_price')
        ->and($variant)->not->toHaveKey('stock_quantity')
        ->and($variant)->not->toHaveKey('low_stock_threshold');
});

test('direct order constants are absent from product model', function () {
    // These constants were removed
    expect(class_exists(Product::class))->toBeTrue();
    expect(property_exists(Product::class, 'DIRECTLY_ORDERABLE_TYPES'))->toBeFalse();
    expect(property_exists(Product::class, 'CUSTOMER_ORDERABLE_TYPES'))->toBeFalse();
});
