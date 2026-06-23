<?php

use App\Models\Appointment;
use App\Models\LensType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
});

test('customers can submit order requests with item snapshots and lens type selection', function () {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
    ]);
    $product = Product::factory()->create([
        'name' => 'Aviator Frame',
        'is_active' => true,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'name' => 'Silver',
        'sku' => 'AVF-SLV-001',
        'price' => 189.99,
        'is_active' => true,
    ]);
    $lensType = LensType::factory()->create([
        'name' => 'single_vision',
        'price' => null,
    ]);

    $response = $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'appointment_id' => $appointment->id,
            'is_non_prescription' => false,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'lens_type_id' => $lensType->id,
                    'quantity' => 1,
                ],
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'requested')
        ->assertJsonPath('data.is_non_prescription', false)
        ->assertJsonPath('data.appointment_id', $appointment->id)
        ->assertJsonPath('data.items.0.product_name', 'Aviator Frame')
        ->assertJsonPath('data.items.0.variant_name', 'Silver')
        ->assertJsonPath('data.items.0.lens_type_name', 'single_vision')
        ->assertJsonPath('data.items.0.unit_price', '189.99')
        ->assertJsonPath('data.total_amount', '189.99');

    $this->assertDatabaseHas(Order::class, [
        'customer_id' => $customer->id,
        'appointment_id' => $appointment->id,
        'is_non_prescription' => false,
        'total_amount' => '189.99',
        'order_status_id' => OrderStatus::query()->where('name', 'requested')->value('id'),
    ]);

    $this->assertDatabaseHas(OrderItem::class, [
        'product_variant_id' => $variant->id,
        'lens_type_id' => $lensType->id,
        'product_name' => 'Aviator Frame',
        'variant_name' => 'Silver',
        'lens_type_name' => 'single_vision',
        'unit_price' => '189.99',
        'quantity' => 1,
        'subtotal' => '189.99',
    ]);
});

test('order items keep catalog snapshots after product data changes', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create([
        'name' => 'Original Frame',
        'is_active' => true,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'name' => 'Original Variant',
        'price' => 120.00,
        'is_active' => true,
    ]);
    $lensType = LensType::factory()->create([
        'name' => 'progressive',
        'price' => null,
    ]);

    $response = $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'lens_type_id' => $lensType->id,
                    'quantity' => 2,
                ],
            ],
        ]);

    $orderId = $response->json('data.id');

    $product->update(['name' => 'Renamed Frame']);
    $variant->update(['name' => 'Renamed Variant', 'price' => 999.99]);
    $lensType->update(['name' => 'photochromic']);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/orders/{$orderId}")
        ->assertSuccessful()
        ->assertJsonPath('data.items.0.product_name', 'Original Frame')
        ->assertJsonPath('data.items.0.variant_name', 'Original Variant')
        ->assertJsonPath('data.items.0.lens_type_name', 'progressive')
        ->assertJsonPath('data.items.0.unit_price', '120.00')
        ->assertJsonPath('data.items.0.subtotal', '240.00')
        ->assertJsonPath('data.total_amount', '240.00');
});

test('customers can list only their own orders', function () {
    $customer = User::factory()->customer()->create();
    $otherCustomer = User::factory()->customer()->create();

    $ownOrders = Order::factory()->count(2)->create([
        'customer_id' => $customer->id,
    ]);

    Order::factory()->create([
        'customer_id' => $otherCustomer->id,
    ]);

    $response = $this->actingAs($customer, 'sanctum')
        ->getJson('/api/orders');

    $response->assertSuccessful();

    $orderIds = collect($response->json('data'))->pluck('id')->all();

    expect($orderIds)
        ->toEqualCanonicalizing($ownOrders->pluck('id')->all())
        ->and($orderIds)->toHaveCount(2);
});

test('order requests reject invalid variants lens types and appointment ownership', function () {
    $customer = User::factory()->customer()->create();
    $otherAppointment = Appointment::factory()->create();
    $inactiveProduct = Product::factory()->create(['is_active' => false]);
    $inactiveVariant = ProductVariant::factory()->for($inactiveProduct)->create(['is_active' => true]);
    $disabledVariant = ProductVariant::factory()->create(['is_active' => false]);
    $validVariant = ProductVariant::factory()->create(['is_active' => true]);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'appointment_id' => $otherAppointment->id,
            'is_non_prescription' => true,
            'items' => [
                [
                    'product_variant_id' => 99999,
                    'lens_type_id' => 99999,
                    'quantity' => 1,
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['appointment_id', 'items.0.product_variant_id', 'items.0.lens_type_id']);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [
                [
                    'product_variant_id' => $inactiveVariant->id,
                    'lens_type_id' => LensType::factory()->create()->id,
                    'quantity' => 1,
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.product_variant_id']);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [
                [
                    'product_variant_id' => $disabledVariant->id,
                    'lens_type_id' => LensType::factory()->create()->id,
                    'quantity' => 1,
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.product_variant_id']);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [
                [
                    'product_variant_id' => $validVariant->id,
                    'lens_type_id' => 99999,
                    'quantity' => 1,
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.lens_type_id']);
});

test('order requests require items and non prescription flag', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items', 'is_non_prescription']);
});

test('order endpoints require authentication', function () {
    $this->postJson('/api/orders', [])->assertUnauthorized();
    $this->getJson('/api/orders')->assertUnauthorized();
});

test('order item response includes product and variant image urls', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create([
        'is_active' => true,
        'images' => ['products/hero.jpg'],
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'is_active' => true,
        'images' => ['variants/color.jpg'],
    ]);
    $lensType = LensType::factory()->create(['price' => null]);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [
                ['product_variant_id' => $variant->id, 'lens_type_id' => $lensType->id, 'quantity' => 1],
            ],
        ]);

    $order = Order::query()->where('customer_id', $customer->id)->firstOrFail();

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.items.0.product_images', ['products/hero.jpg'])
        ->assertJsonPath('data.items.0.variant_images', ['variants/color.jpg']);
});
