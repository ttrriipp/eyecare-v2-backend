<?php

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated customers can list active products', function () {
    $customer = User::factory()->customer()->create();

    $activeProduct = Product::factory()->create(['name' => 'Active Frame']);
    Product::factory()->inactive()->create(['name' => 'Inactive Frame']);

    $response = $this->actingAs($customer, 'sanctum')
        ->getJson('/api/products');

    $response->assertSuccessful();

    $productIds = collect($response->json('data'))->pluck('id')->all();

    expect($productIds)->toContain($activeProduct->id)
        ->and($productIds)->not->toContain(Product::query()->where('name', 'Inactive Frame')->value('id'));
});

test('authenticated customers can view active product details with variants', function () {
    $customer = User::factory()->customer()->create();

    $product = Product::factory()->create(['name' => 'Demo Frame']);
    $variant = ProductVariant::factory()->for($product)->arEligible()->create([
        'name' => 'Matte Black',
        'ar_asset_reference' => 'frames/demo-matte-black.glb',
    ]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$product->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.name', 'Demo Frame')
        ->assertJsonPath('data.variants.0.id', $variant->id)
        ->assertJsonPath('data.variants.0.ar_eligible', true)
        ->assertJsonPath('data.variants.0.ar_asset_reference', 'frames/demo-matte-black.glb')
        ->assertJsonPath('data.variants.0.in_stock', true);
});

test('variant with zero stock shows in_stock as false', function () {
    $customer = User::factory()->customer()->create();

    $product = Product::factory()->create();
    ProductVariant::factory()->for($product)->create(['stock_quantity' => 0]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$product->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.variants.0.in_stock', false);
});

test('inactive products are hidden from product detail endpoint', function () {
    $customer = User::factory()->customer()->create();
    $inactiveProduct = Product::factory()->inactive()->create();

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$inactiveProduct->id}")
        ->assertNotFound();
});

test('product catalog responses exclude biometric fields', function () {
    $customer = User::factory()->customer()->create();

    $product = Product::factory()->create();
    ProductVariant::factory()->for($product)->arEligible()->create();

    $response = $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$product->id}");

    $response->assertSuccessful();

    $payload = json_encode($response->json());

    expect($payload)->not->toContain('face_geometry')
        ->and($payload)->not->toContain('facial_landmarks')
        ->and($payload)->not->toContain('biometric_identifier')
        ->and($payload)->not->toContain('ar_analytics');
});

test('unauthenticated users cannot access product catalog endpoints', function () {
    $product = Product::factory()->create();

    $this->getJson('/api/products')->assertUnauthorized();
    $this->getJson("/api/products/{$product->id}")->assertUnauthorized();
});

test('mobile api only returns frame products in listing', function () {
    $customer = User::factory()->customer()->create();

    $frame = Product::factory()->create(['product_type' => 'frame']);
    $lens = Product::factory()->create(['product_type' => 'lens']);
    $accessory = Product::factory()->create(['product_type' => 'accessory']);

    $response = $this->actingAs($customer, 'sanctum')->getJson('/api/products');

    $productIds = collect($response->json('data'))->pluck('id')->all();

    expect($productIds)->toContain($frame->id)
        ->and($productIds)->not->toContain($lens->id)
        ->and($productIds)->not->toContain($accessory->id);
});

test('mobile api returns 404 for non-frame product detail', function () {
    $customer = User::factory()->customer()->create();
    $lens = Product::factory()->create(['product_type' => 'lens']);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$lens->id}")
        ->assertNotFound();
});
