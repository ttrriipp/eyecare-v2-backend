<?php

use App\Models\Product;
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

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$product->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.product_type', 'frame');
});

function productTypeGuardMigration(): Migration
{
    return require database_path('migrations/2026_07_17_140129_guard_against_legacy_general_product_type.php');
}
