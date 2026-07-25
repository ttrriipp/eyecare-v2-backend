<?php

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('products table has a product_type column', function () {
    expect(Schema::hasColumn('products', 'product_type'))->toBeTrue();
});

test('product factory defaults to frame type', function () {
    $product = Product::factory()->create();

    expect($product->product_type)->toBe('frame');
});

test('product exposes the supported type labels', function () {
    expect(Product::TYPE_OPTIONS)->toBe([
        'frame' => 'Frame',
        'lens' => 'Lens',
        'contact_lens' => 'Contact Lens',
        'accessory' => 'Accessory',
    ]);
});

test('product exposes directly orderable types', function () {
    expect(Product::DIRECTLY_ORDERABLE_TYPES)->toBe([
        'frame',
        'contact_lens',
        'accessory',
    ]);
});

test('product exposes customer orderable types', function () {
    expect(Product::CUSTOMER_ORDERABLE_TYPES)->toBe([
        'accessory',
    ]);
});

test('mobile catalog product scope includes accessories and ar capable frames', function () {
    $accessory = Product::factory()->accessory()->create();
    $arFrame = Product::factory()->create();
    ProductVariant::factory()->for($arFrame)->arEligible()->create();

    $frameWithoutAr = Product::factory()->create();
    ProductVariant::factory()->for($frameWithoutAr)->create();

    $inactiveAccessory = Product::factory()->accessory()->inactive()->create();
    $contactLens = Product::factory()->contactLens()->create();

    $productIds = Product::query()
        ->visibleInMobileCatalog()
        ->pluck('id');

    expect($productIds->all())
        ->toEqualCanonicalizing([$accessory->id, $arFrame->id])
        ->and($productIds)->not->toContain($frameWithoutAr->id)
        ->and($productIds)->not->toContain($inactiveAccessory->id)
        ->and($productIds)->not->toContain($contactLens->id);
});

test('mobile catalog variant scope includes accessory variants and ar ready frame variants', function () {
    $accessoryVariant = ProductVariant::factory()
        ->for(Product::factory()->accessory())
        ->create();
    $inactiveAccessoryVariant = ProductVariant::factory()
        ->for(Product::factory()->accessory())
        ->create(['is_active' => false]);
    $arFrameVariant = ProductVariant::factory()
        ->for(Product::factory())
        ->arEligible()
        ->create();
    $frameVariantWithoutAr = ProductVariant::factory()
        ->for(Product::factory())
        ->create();
    $arVariantWithoutAsset = ProductVariant::factory()
        ->for(Product::factory())
        ->create([
            'ar_eligible' => true,
            'ar_asset_reference' => null,
        ]);
    $inactiveFrameArVariant = ProductVariant::factory()
        ->for(Product::factory()->inactive())
        ->arEligible()
        ->create();
    $contactLensVariant = ProductVariant::factory()
        ->for(Product::factory()->contactLens())
        ->create();

    $variantIds = ProductVariant::query()
        ->visibleInMobileCatalog()
        ->pluck('id');

    expect($variantIds->all())
        ->toEqualCanonicalizing([$accessoryVariant->id, $arFrameVariant->id])
        ->and($variantIds)->not->toContain($inactiveAccessoryVariant->id)
        ->and($variantIds)->not->toContain($frameVariantWithoutAr->id)
        ->and($variantIds)->not->toContain($arVariantWithoutAsset->id)
        ->and($variantIds)->not->toContain($inactiveFrameArVariant->id)
        ->and($variantIds)->not->toContain($contactLensVariant->id);
});

test('product factory creates contact lens and accessory types', function () {
    $contactLens = Product::factory()->contactLens()->create();
    $accessory = Product::factory()->accessory()->create();

    expect($contactLens->product_type)->toBe('contact_lens')
        ->and($accessory->product_type)->toBe('accessory');
});

test('legacy product type guard passes when general products do not exist', function () {
    productTypeGuardMigration()->up();

    expect(Product::query()->where('product_type', 'general')->doesntExist())->toBeTrue();
});

test('legacy product type guard rejects general products without changing them', function () {
    $legacyProduct = Product::factory()->create(['product_type' => 'general']);

    expect(fn () => productTypeGuardMigration()->up())
        ->toThrow(RuntimeException::class, 'Legacy general products must be explicitly reclassified');

    expect($legacyProduct->fresh()->product_type)->toBe('general');
});

test('product api response includes product_type', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create(['product_type' => 'frame']);
    ProductVariant::factory()->for($product)->arEligible()->create();

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$product->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.product_type', 'frame');
});

function productTypeGuardMigration(): Migration
{
    return require database_path('migrations/2026_07_17_140129_guard_against_legacy_general_product_type.php');
}
