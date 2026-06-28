<?php

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
    $this->seed(BillingStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
});

test('customer can cancel their own requested order', function () {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->create(['customer_id' => $customer->id]);

    $response = $this->actingAs($customer)->postJson("/api/orders/{$order->id}/cancel");

    $response->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($order->fresh()->status->name)->toBe('cancelled');
});

test('customer cannot cancel another customers order', function () {
    $customer = User::factory()->customer()->create();
    $other = User::factory()->customer()->create();
    $order = Order::factory()->create(['customer_id' => $other->id]);

    $this->actingAs($customer)
        ->postJson("/api/orders/{$order->id}/cancel")
        ->assertForbidden();
});

test('customer cannot cancel a confirmed order', function () {
    $customer = User::factory()->customer()->create();
    $confirmed = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $order = Order::factory()->create(['customer_id' => $customer->id, 'order_status_id' => $confirmed->id]);

    $this->actingAs($customer)
        ->postJson("/api/orders/{$order->id}/cancel")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('order');
});

test('unauthenticated request returns 401', function () {
    $order = Order::factory()->create();

    $this->postJson("/api/orders/{$order->id}/cancel")
        ->assertUnauthorized();
});
