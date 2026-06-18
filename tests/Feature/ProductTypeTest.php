<?php

use App\Models\Product;
use App\Models\User;
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

test('product factory accessory state creates accessory type', function () {
    $product = Product::factory()->accessory()->create();

    expect($product->product_type)->toBe('accessory');
});

test('product api response includes product_type', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create(['product_type' => 'frame']);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/products/{$product->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.product_type', 'frame');
});
