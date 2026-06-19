<?php

use App\Actions\Orders\UpdateOrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\InventoryMovementTypeSeeder;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
});

test('staff can advance orders through the full workflow chain', function () {
    $staff = User::factory()->staff()->create();
    $order = Order::factory()->create(['is_non_prescription' => true]);

    $chain = [
        'requested' => 'confirmed',
        'confirmed' => 'preparing',
        'preparing' => 'ready_for_pickup',
        'ready_for_pickup' => 'completed',
    ];

    foreach ($chain as $from => $to) {
        $this->actingAs($staff, 'sanctum')
            ->patchJson("/api/staff/orders/{$order->id}/status", ['status' => $to])
            ->assertSuccessful()
            ->assertJsonPath('data.status', $to);

        expect($order->fresh()->status->name)->toBe($to);
    }
});

test('staff can cancel orders from any cancellable state', function (string $currentStatusName) {
    $staff = User::factory()->staff()->create();
    $currentStatus = OrderStatus::query()->where('name', $currentStatusName)->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => true,
        'order_status_id' => $currentStatus->id,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", ['status' => 'cancelled'])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'cancelled');
})->with([
    'from requested' => ['requested'],
    'from under_review' => ['requested'],
    'from confirmed' => ['confirmed'],
    'from preparing' => ['preparing'],
    'from ready_for_pickup' => ['ready_for_pickup'],
]);

test('invalid status transitions are rejected', function () {
    $staff = User::factory()->staff()->create();
    $order = Order::factory()->create(['is_non_prescription' => true]);

    // requested → completed is not in the allowed transitions
    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", ['status' => 'completed'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('completed and cancelled orders cannot be transitioned further', function (string $terminalStatus) {
    $staff = User::factory()->staff()->create();
    $terminalOrderStatus = OrderStatus::query()->where('name', $terminalStatus)->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => true,
        'order_status_id' => $terminalOrderStatus->id,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", ['status' => 'requested'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
})->with([
    'completed' => ['completed'],
    'cancelled' => ['cancelled'],
]);

test('confirming an order sets confirmed_at', function () {
    $staff = User::factory()->staff()->create();
    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => true,
        'order_status_id' => $requestedStatus->id,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", ['status' => 'confirmed'])
        ->assertSuccessful();

    expect($order->fresh()->confirmed_at)->not->toBeNull();
});

test('completing an order sets completed_at', function () {
    $staff = User::factory()->staff()->create();
    $readyStatus = OrderStatus::query()->where('name', 'ready_for_pickup')->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => true,
        'order_status_id' => $readyStatus->id,
        'confirmed_at' => now(),
    ]);

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", ['status' => 'completed'])
        ->assertSuccessful();

    expect($order->fresh()->completed_at)->not->toBeNull();
});

test('prescription orders cannot be confirmed without a customer prescription', function () {
    $staff = User::factory()->staff()->create();
    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => false,
        'order_status_id' => $requestedStatus->id,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", ['status' => 'confirmed'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('prescription orders can be confirmed when the customer has a prescription', function () {
    $staff = User::factory()->staff()->create();
    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => false,
        'order_status_id' => $requestedStatus->id,
    ]);

    Prescription::factory()->create([
        'customer_id' => $order->customer_id,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", ['status' => 'confirmed'])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'confirmed');
});

test('non prescription orders can be confirmed without prescription data', function () {
    $staff = User::factory()->staff()->create();
    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => true,
        'order_status_id' => $requestedStatus->id,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", ['status' => 'confirmed'])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'confirmed');

    expect(Prescription::query()->where('customer_id', $order->customer_id)->exists())->toBeFalse();
});

test('customers cannot update order status through the staff endpoint', function () {
    $customer = User::factory()->customer()->create();
    $order = Order::factory()->create([
        'customer_id' => $customer->id,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", [
            'status' => 'confirmed',
        ])
        ->assertForbidden();
});

test('admin users can update order status through the staff endpoint', function () {
    $admin = User::factory()->admin()->create();
    $order = Order::factory()->create([
        'is_non_prescription' => true,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", [
            'status' => 'confirmed',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'confirmed');
});

test('order confirmation throws ValidationException when frame variant has insufficient stock', function () {
    $this->seed(InventoryMovementTypeSeeder::class);

    $variant = ProductVariant::factory()->create(['stock_quantity' => 0]);

    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $order = Order::factory()->create([
        'order_status_id' => $requestedStatus->id,
        'is_non_prescription' => true,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'quantity' => 1,
    ]);

    expect(fn () => app(UpdateOrderStatus::class)->handle($order, 'confirmed'))
        ->toThrow(ValidationException::class);

    // Order status must remain unchanged
    expect($order->fresh()->status->name)->toBe('requested');
});
