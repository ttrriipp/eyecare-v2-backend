<?php

use App\Models\LensCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
});

test('lens_categories table has a price column', function () {
    expect(Schema::hasColumn('lens_categories', 'price'))->toBeTrue();
});

test('order_items table has a lens_type_price column', function () {
    expect(Schema::hasColumn('order_items', 'lens_type_price'))->toBeTrue();
});

test('customer accessory orders reject priced lens categories', function () {
    $customer = User::factory()->customer()->create();
    $variant = ProductVariant::factory()
        ->for(Product::factory()->accessory())
        ->create(['price' => '3000.00']);
    $lensCategory = LensCategory::factory()->create(['price' => '5000.00']);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'lens_category_id' => $lensCategory->id,
                    'quantity' => 1,
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.lens_category_id']);
});

test('customer accessory orders reject the legacy lens type alias', function () {
    $customer = User::factory()->customer()->create();
    $variant = ProductVariant::factory()
        ->for(Product::factory()->accessory())
        ->create(['price' => '2000.00']);
    $lensCategory = LensCategory::factory()->create(['price' => '4000.00']);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [
                ['product_variant_id' => $variant->id, 'lens_type_id' => $lensCategory->id, 'quantity' => 1],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.lens_type_id']);
});

test('customer accessory orders reject lens categories without a price', function () {
    $customer = User::factory()->customer()->create();
    $variant = ProductVariant::factory()
        ->for(Product::factory()->accessory())
        ->create(['price' => '3000.00']);
    $lensCategory = LensCategory::factory()->create(['price' => null]);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [
                ['product_variant_id' => $variant->id, 'lens_category_id' => $lensCategory->id, 'quantity' => 1],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.lens_category_id']);
});
