<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SavedFrame;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->brand = Brand::factory()->create();
    $this->user = User::factory()->patient()->create();
});

test('frames endpoint returns only frame products', function () {
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

    // Accessory should not appear
    Product::factory()->create([
        'product_type' => 'accessory',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/frames')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product_type', 'frame');
});

test('frames endpoint excludes internal fields', function () {
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

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/frames')
        ->assertOk();

    // Price IS included (customer-facing)
    $response->assertJsonPath('data.0.variants.0.price', '159.99');

    // Internal fields are NOT included
    $variant = $response->json('data.0.variants.0');
    expect($variant)->not->toHaveKey('cost_price')
        ->and($variant)->not->toHaveKey('stock_quantity')
        ->and($variant)->not->toHaveKey('low_stock_threshold');
});

test('frames endpoint requires authentication', function () {
    $this->getJson('/api/v1/frames')->assertUnauthorized();
});

test('unlinked patient account can browse the frame catalog', function () {
    $user = User::factory()->create();
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

    $this->actingAs($user)
        ->getJson('/api/v1/frames')
        ->assertOk()
        ->assertJsonPath('data.0.id', $frame->id);

    $this->actingAs($user)
        ->getJson("/api/v1/frames/{$frame->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $frame->id);
});

test('frames endpoint returns paginated results', function () {
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

    $this->actingAs($this->user)
        ->getJson('/api/v1/frames')
        ->assertOk()
        ->assertJsonStructure([
            'data',
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
});

test('frame detail returns single frame with variants', function () {
    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'is_active' => true,
        'ar_eligible' => true,
        'ar_asset_reference' => 'ar-assets/test.glb',
    ]);

    $this->actingAs($this->user)
        ->getJson("/api/v1/frames/{$frame->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $frame->id)
        ->assertJsonCount(1, 'data.variants');
});

test('frame detail returns 404 for non-frame products', function () {
    $accessory = Product::factory()->create([
        'product_type' => 'accessory',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);

    $this->actingAs($this->user)
        ->getJson("/api/v1/frames/{$accessory->id}")
        ->assertNotFound();
});

test('frames can be searched by name', function () {
    $frame1 = Product::factory()->create([
        'product_type' => 'frame',
        'name' => 'Classic Rectangle',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
    $frame2 = Product::factory()->create([
        'product_type' => 'frame',
        'name' => 'Aviator Style',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);

    foreach ([$frame1, $frame2] as $f) {
        ProductVariant::factory()->create([
            'product_id' => $f->id,
            'is_active' => true,
            'ar_eligible' => true,
            'ar_asset_reference' => 'ar-assets/test.glb',
        ]);
    }

    $this->actingAs($this->user)
        ->getJson('/api/v1/frames?search=classic')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Classic Rectangle');
});

test('frame variants include is_saved for authenticated account', function () {
    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'is_active' => true,
        'ar_eligible' => true,
        'ar_asset_reference' => 'ar-assets/test.glb',
    ]);

    SavedFrame::factory()->forAccount($this->user)->forVariant($variant)->create();

    $this->actingAs($this->user)
        ->getJson('/api/v1/frames')
        ->assertOk()
        ->assertJsonPath('data.0.variants.0.is_saved', true);
});

test('frame variants show is_saved false when not saved', function () {
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

    $this->actingAs($this->user)
        ->getJson('/api/v1/frames')
        ->assertOk()
        ->assertJsonPath('data.0.variants.0.is_saved', false);
});

test('is_saved is account-specific', function () {
    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'is_active' => true,
        'ar_eligible' => true,
        'ar_asset_reference' => 'ar-assets/test.glb',
    ]);

    $otherUser = User::factory()->create();
    SavedFrame::factory()->forAccount($otherUser)->forVariant($variant)->create();

    $this->actingAs($this->user)
        ->getJson('/api/v1/frames')
        ->assertOk()
        ->assertJsonPath('data.0.variants.0.is_saved', false);
});

test('frame detail includes is_saved', function () {
    $frame = Product::factory()->create([
        'product_type' => 'frame',
        'is_active' => true,
        'brand_id' => $this->brand->id,
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $frame->id,
        'is_active' => true,
        'ar_eligible' => true,
        'ar_asset_reference' => 'ar-assets/test.glb',
    ]);

    SavedFrame::factory()->forAccount($this->user)->forVariant($variant)->create();

    $this->actingAs($this->user)
        ->getJson("/api/v1/frames/{$frame->id}")
        ->assertOk()
        ->assertJsonPath('data.variants.0.is_saved', true);
});
