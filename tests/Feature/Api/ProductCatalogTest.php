<?php

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated customers can list active accessories', function () {
    $customer = User::factory()->customer()->create();

    $activeProduct = Product::factory()->accessory()->create(['name' => 'Active Accessory']);
    ProductVariant::factory()->for($activeProduct)->create(['name' => 'Standard']);
    Product::factory()->accessory()->inactive()->create(['name' => 'Inactive Accessory']);

    $response = $this->actingAs($customer, 'sanctum')
        ->getJson('/api/products');

    $response->assertSuccessful();

    $productIds = collect($response->json('data'))->pluck('id')->all();

    expect($productIds)->toContain($activeProduct->id)
        ->and($productIds)->not->toContain(Product::query()->where('name', 'Inactive Accessory')->value('id'));
});

test('product listing returns null category for an active directly orderable product without a category', function () {
    $customer = User::factory()->customer()->create();

    $product = Product::factory()->accessory()->create([
        'category_id' => null,
    ]);
    ProductVariant::factory()->for($product)->create(['name' => 'Standard']);

    $this->actingAs($customer, 'sanctum')
        ->getJson('/api/products')
        ->assertOk()
        ->assertJson([
            'data' => [
                [
                    'id' => $product->id,
                    'category' => null,
                ],
            ],
        ]);
});

test('product listing includes accessories and ar capable frames only', function () {
    $customer = User::factory()->customer()->create();

    $arFrame = Product::factory()->create(['name' => 'AR Frame']);
    ProductVariant::factory()->for($arFrame)->arEligible()->create();
    $frameWithoutAr = Product::factory()->create(['name' => 'Frame Without AR']);
    $contactLens = Product::factory()->contactLens()->create(['name' => 'Listed Contact Lens']);
    $accessory = Product::factory()->accessory()->create(['name' => 'Listed Solution']);
    ProductVariant::factory()->for($accessory)->create(['name' => 'Standard']);
    $lens = Product::factory()->create(['name' => 'Hidden Lens', 'product_type' => 'lens']);
    $legacyGeneral = Product::factory()->create(['name' => 'Legacy General', 'product_type' => 'general']);

    $response = $this->actingAs($customer, 'sanctum')
        ->getJson('/api/products');

    $response->assertSuccessful();

    $productIds = collect($response->json('data'))->pluck('id')->all();

    expect($productIds)->toContain($arFrame->id)
        ->and($productIds)->toContain($accessory->id)
        ->and($productIds)->not->toContain($frameWithoutAr->id)
        ->and($productIds)->not->toContain($contactLens->id)
        ->and($productIds)->not->toContain($lens->id)
        ->and($productIds)->not->toContain($legacyGeneral->id);
});

test('accessory and ar frame details are accessible while other product types return 404', function () {
    $customer = User::factory()->customer()->create();

    $arFrame = Product::factory()->create();
    ProductVariant::factory()->for($arFrame)->arEligible()->create();
    $frameWithoutAr = Product::factory()->create();
    $contactLens = Product::factory()->contactLens()->create();
    $accessory = Product::factory()->accessory()->create();
    ProductVariant::factory()->for($accessory)->create(['name' => 'Standard']);
    $lens = Product::factory()->create(['product_type' => 'lens']);
    $legacyGeneral = Product::factory()->create(['product_type' => 'general']);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$arFrame->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.product_type', 'frame');

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$accessory->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.product_type', 'accessory');

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$frameWithoutAr->id}")
        ->assertNotFound();

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$contactLens->id}")
        ->assertNotFound();

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$lens->id}")
        ->assertNotFound();

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$legacyGeneral->id}")
        ->assertNotFound();
});

test('ar frame details include only active ar ready variants', function () {
    $customer = User::factory()->customer()->create();

    $product = Product::factory()->create(['name' => 'Demo Frame']);
    $variant = ProductVariant::factory()->for($product)->arEligible()->create([
        'name' => 'Matte Black',
        'ar_asset_reference' => 'frames/demo-matte-black.glb',
        'stock_quantity' => 5,
    ]);
    ProductVariant::factory()->for($product)->create([
        'name' => 'No AR Asset',
    ]);
    ProductVariant::factory()->for($product)->arEligible()->create([
        'name' => 'Inactive AR',
        'is_active' => false,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$product->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.name', 'Demo Frame')
        ->assertJsonPath('data.variants.0.id', $variant->id)
        ->assertJsonPath('data.variants.0.ar_eligible', true)
        ->assertJsonPath('data.variants.0.ar_asset_reference', 'frames/demo-matte-black.glb')
        ->assertJsonPath('data.variants.0.in_stock', true)
        ->assertJsonCount(1, 'data.variants');
});

test('product details return null category for an active directly orderable product without a category', function () {
    $customer = User::factory()->customer()->create();

    $product = Product::factory()->accessory()->create([
        'category_id' => null,
    ]);
    ProductVariant::factory()->for($product)->create(['name' => 'Standard']);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$product->id}")
        ->assertOk()
        ->assertJson([
            'data' => [
                'id' => $product->id,
                'category' => null,
            ],
        ]);
});

test('accessory variant with zero stock shows in_stock as false', function () {
    $customer = User::factory()->customer()->create();

    $product = Product::factory()->accessory()->create();
    ProductVariant::factory()->for($product)->create(['stock_quantity' => 0]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$product->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.variants.0.in_stock', false);
});

test('inactive products are hidden from product detail endpoint', function () {
    $customer = User::factory()->customer()->create();
    $inactiveProduct = Product::factory()->accessory()->inactive()->create();

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

test('mobile api excludes frames without an active ar asset', function () {
    $customer = User::factory()->customer()->create();

    $arFrame = Product::factory()->create();
    ProductVariant::factory()->for($arFrame)->arEligible()->create();

    $frameWithInactiveAr = Product::factory()->create();
    ProductVariant::factory()->for($frameWithInactiveAr)->arEligible()->create(['is_active' => false]);

    $frameWithoutAsset = Product::factory()->create();
    ProductVariant::factory()->for($frameWithoutAsset)->create([
        'ar_eligible' => true,
        'ar_asset_reference' => null,
    ]);

    $accessory = Product::factory()->accessory()->create();
    ProductVariant::factory()->for($accessory)->create(['name' => 'Standard']);

    $response = $this->actingAs($customer, 'sanctum')->getJson('/api/products');

    $productIds = collect($response->json('data'))->pluck('id')->all();

    expect($productIds)->toContain($arFrame->id)
        ->and($productIds)->toContain($accessory->id)
        ->and($productIds)->not->toContain($frameWithInactiveAr->id)
        ->and($productIds)->not->toContain($frameWithoutAsset->id);
});

test('accessory list and detail return the same active non archived variants and uploaded product image', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->accessory()->create([
        'name' => 'Lens Wipes',
        'images' => ['products/example.jpg'],
    ]);
    $activeVariant = ProductVariant::factory()->for($product)->create([
        'name' => 'Standard',
        'sku' => 'WEWW-STD',
        'price' => '100.00',
        'stock_quantity' => 5,
        'images' => [],
    ]);
    ProductVariant::factory()->for($product)->create([
        'name' => 'Inactive',
        'is_active' => false,
    ]);
    $archivedVariant = ProductVariant::factory()->for($product)->create([
        'name' => 'Archived',
        'is_active' => true,
    ]);
    $archivedVariant->delete();

    $listResponse = $this->actingAs($customer, 'sanctum')
        ->getJson('/api/products')
        ->assertSuccessful();

    $listedProduct = collect($listResponse->json('data'))
        ->firstWhere('id', $product->id);

    expect($listedProduct)->not->toBeNull()
        ->and($listedProduct['images'])->toBe(['products/example.jpg'])
        ->and(collect($listedProduct['variants'])->pluck('id')->all())->toBe([$activeVariant->id]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$product->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.product_type', 'accessory')
        ->assertJsonPath('data.images.0', 'products/example.jpg')
        ->assertJsonPath('data.variants.0.id', $activeVariant->id)
        ->assertJsonPath('data.variants.0.name', 'Standard')
        ->assertJsonPath('data.variants.0.sku', 'WEWW-STD')
        ->assertJsonPath('data.variants.0.price', '100.00')
        ->assertJsonPath('data.variants.0.in_stock', true)
        ->assertJsonPath('data.variants.0.images', [])
        ->assertJsonCount(1, 'data.variants');
});

test('accessory detail preserves an uploaded variant image when product images are empty', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->accessory()->create([
        'images' => [],
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'name' => 'Standard',
        'images' => ['variants/example.jpg'],
    ]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$product->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.images', [])
        ->assertJsonPath('data.variants.0.id', $variant->id)
        ->assertJsonPath('data.variants.0.images.0', 'variants/example.jpg');
});

test('accessories without active non archived variants are excluded from list and detail', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->accessory()->create([
        'name' => 'Unavailable Accessory',
    ]);
    ProductVariant::factory()->for($product)->create([
        'is_active' => false,
    ]);
    $archivedVariant = ProductVariant::factory()->for($product)->create([
        'is_active' => true,
    ]);
    $archivedVariant->delete();

    $listResponse = $this->actingAs($customer, 'sanctum')
        ->getJson('/api/products')
        ->assertSuccessful();

    expect(collect($listResponse->json('data'))->pluck('id')->all())
        ->not->toContain($product->id);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$product->id}")
        ->assertNotFound();
});

test('mobile api returns 404 for optical lens product detail', function () {
    $customer = User::factory()->customer()->create();
    $lens = Product::factory()->create(['product_type' => 'lens']);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$lens->id}")
        ->assertNotFound();
});
