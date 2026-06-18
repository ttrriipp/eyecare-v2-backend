<?php

use App\Models\LensType;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
});

test('lens_types table has a price column', function () {
    expect(Schema::hasColumn('lens_types', 'price'))->toBeTrue();
});

test('order_items table has a lens_type_price column', function () {
    expect(Schema::hasColumn('order_items', 'lens_type_price'))->toBeTrue();
});

test('order total includes lens type price', function () {
    $customer = User::factory()->customer()->create();
    $variant = ProductVariant::factory()->create(['price' => '3000.00']);
    $lensType = LensType::factory()->create(['price' => '5000.00']);

    $response = $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'lens_type_id' => $lensType->id,
                    'quantity' => 1,
                ],
            ],
        ]);

    $response->assertCreated();

    $order = Order::query()->where('customer_id', $customer->id)->firstOrFail();

    expect($order->total_amount)->toBe('8000.00')
        ->and($order->items->first()->lens_type_price)->toBe('5000.00');
});

test('order item snapshots lens_type_price at time of order', function () {
    $customer = User::factory()->customer()->create();
    $variant = ProductVariant::factory()->create(['price' => '2000.00']);
    $lensType = LensType::factory()->create(['price' => '4000.00']);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [
                ['product_variant_id' => $variant->id, 'lens_type_id' => $lensType->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated();

    // Change the lens type price after order
    $lensType->update(['price' => '9999.00']);

    $order = Order::query()->where('customer_id', $customer->id)->firstOrFail();

    // Snapshot should remain at original price
    expect($order->items->first()->lens_type_price)->toBe('4000.00');
});

test('order total is correct when lens type has no price', function () {
    $customer = User::factory()->customer()->create();
    $variant = ProductVariant::factory()->create(['price' => '3000.00']);
    $lensType = LensType::factory()->create(['price' => null]);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [
                ['product_variant_id' => $variant->id, 'lens_type_id' => $lensType->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated();

    $order = Order::query()->where('customer_id', $customer->id)->firstOrFail();

    expect($order->total_amount)->toBe('3000.00');
});
