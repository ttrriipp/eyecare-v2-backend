<?php

use App\Models\Appointment;
use App\Models\LensCategory;
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

test('customers can submit accessory order requests with item snapshots', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->accessory()->create([
        'name' => 'Cleaning Kit',
        'is_active' => true,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'name' => 'Standard',
        'sku' => 'KIT-STD-001',
        'price' => 189.99,
        'is_active' => true,
    ]);

    $response = $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'appointment_id' => null,
            'is_non_prescription' => true,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 1,
                ],
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'requested')
        ->assertJsonPath('data.is_non_prescription', true)
        ->assertJsonPath('data.appointment_id', null)
        ->assertJsonPath('data.items.0.product_name', 'Cleaning Kit')
        ->assertJsonPath('data.items.0.variant_name', 'Standard')
        ->assertJsonPath('data.items.0.lens_type_name', null)
        ->assertJsonPath('data.items.0.unit_price', '189.99')
        ->assertJsonPath('data.total_amount', '189.99');

    $this->assertDatabaseHas(Order::class, [
        'customer_id' => $customer->id,
        'appointment_id' => null,
        'is_non_prescription' => true,
        'total_amount' => '189.99',
        'order_status_id' => OrderStatus::query()->where('name', 'requested')->value('id'),
    ]);

    $this->assertDatabaseHas(OrderItem::class, [
        'product_variant_id' => $variant->id,
        'lens_category_id' => null,
        'product_name' => 'Cleaning Kit',
        'variant_name' => 'Standard',
        'lens_category_name' => null,
        'unit_price' => '189.99',
        'quantity' => 1,
        'subtotal' => '189.99',
    ]);
});

test('customer order requests reject a supplied appointment id', function () {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
    ]);
    $variant = ProductVariant::factory()
        ->for(Product::factory()->accessory())
        ->create();

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'appointment_id' => $appointment->id,
            'is_non_prescription' => true,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 1,
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['appointment_id']);

    expect(Order::query()->where('customer_id', $customer->id)->exists())->toBeFalse();
});

test('order items keep catalog snapshots after product data changes', function () {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->accessory()->create([
        'name' => 'Original Accessory',
        'is_active' => true,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'name' => 'Original Variant',
        'price' => 120.00,
        'is_active' => true,
    ]);
    $response = $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'quantity' => 2,
                ],
            ],
        ]);

    $orderId = $response->json('data.id');

    $product->update(['name' => 'Renamed Accessory']);
    $variant->update(['name' => 'Renamed Variant', 'price' => 999.99]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/orders/{$orderId}")
        ->assertSuccessful()
        ->assertJsonPath('data.items.0.product_name', 'Original Accessory')
        ->assertJsonPath('data.items.0.variant_name', 'Original Variant')
        ->assertJsonPath('data.items.0.lens_type_name', null)
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

test('customers can still read historical frame orders', function () {
    $customer = User::factory()->customer()->create();
    $appointment = Appointment::factory()->create([
        'customer_id' => $customer->id,
    ]);
    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'appointment_id' => $appointment->id,
    ]);
    $frameVariant = ProductVariant::factory()->for(Product::factory())->create();

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_variant_id' => $frameVariant->id,
        'product_id' => $frameVariant->product_id,
        'product_name' => 'Historical Frame',
        'variant_name' => $frameVariant->name,
        'variant_sku' => $frameVariant->sku,
        'lens_category_id' => null,
        'lens_category_name' => null,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.appointment_id', $appointment->id)
        ->assertJsonPath('data.items.0.product_name', 'Historical Frame');

    $this->actingAs($customer, 'sanctum')
        ->getJson('/api/orders')
        ->assertOk()
        ->assertJsonPath('data.0.appointment_id', $appointment->id);
});

test('order requests reject invalid variants', function () {
    $customer = User::factory()->customer()->create();
    $inactiveProduct = Product::factory()->accessory()->create(['is_active' => false]);
    $inactiveVariant = ProductVariant::factory()->for($inactiveProduct)->create(['is_active' => true]);
    $disabledVariant = ProductVariant::factory()
        ->for(Product::factory()->accessory())
        ->create(['is_active' => false]);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [
                [
                    'product_variant_id' => 99999,
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
                    'product_variant_id' => $inactiveVariant->id,
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
                    'quantity' => 1,
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.product_variant_id']);
});

test('order requests reject non accessory products', function (string $productType) {
    $customer = User::factory()->customer()->create();
    $product = Product::factory()->create(['product_type' => $productType]);
    $variant = ProductVariant::factory()->for($product)->create(['is_active' => true]);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 1],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.product_variant_id']);
})->with(['frame', 'lens', 'contact_lens', 'general']);

test('order requests accept accessory products', function () {
    $customer = User::factory()->customer()->create();
    $accessoryVariant = ProductVariant::factory()
        ->for(Product::factory()->accessory())
        ->create(['is_active' => true]);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [
                ['product_variant_id' => $accessoryVariant->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.items.0.product_variant_id', $accessoryVariant->id);
});

test('order requests require is non prescription to be true', function () {
    $customer = User::factory()->customer()->create();
    $accessoryVariant = ProductVariant::factory()
        ->for(Product::factory()->accessory())
        ->create();

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => false,
            'items' => [
                ['product_variant_id' => $accessoryVariant->id, 'quantity' => 1],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['is_non_prescription']);
});

test('order requests prohibit lens category fields', function (string $lensCategoryField) {
    $customer = User::factory()->customer()->create();
    $lensCategory = LensCategory::factory()->create();
    $accessoryVariant = ProductVariant::factory()
        ->for(Product::factory()->accessory())
        ->create();

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [
                [
                    'product_variant_id' => $accessoryVariant->id,
                    $lensCategoryField => $lensCategory->id,
                    'quantity' => 1,
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["items.0.{$lensCategoryField}"]);
})->with(['lens_category_id', 'lens_type_id']);

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
    $product = Product::factory()->accessory()->create([
        'is_active' => true,
        'images' => ['products/hero.jpg'],
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'is_active' => true,
        'images' => ['variants/color.jpg'],
    ]);
    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/orders', [
            'is_non_prescription' => true,
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 1],
            ],
        ]);

    $order = Order::query()->where('customer_id', $customer->id)->firstOrFail();

    $this->actingAs($customer, 'sanctum')
        ->getJson("/api/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.items.0.product_images', ['products/hero.jpg'])
        ->assertJsonPath('data.items.0.variant_images', ['variants/color.jpg']);
});
