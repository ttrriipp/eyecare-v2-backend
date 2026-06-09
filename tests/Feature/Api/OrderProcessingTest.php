<?php

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Prescription;
use App\Models\User;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
});

test('staff can move orders through workflow statuses', function (string $status) {
    $staff = User::factory()->staff()->create();
    $order = Order::factory()->create([
        'is_non_prescription' => true,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", [
            'status' => $status,
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.status', $status);

    expect($order->fresh()->status->name)->toBe($status);
})->with([
    'under_review' => ['under_review'],
    'confirmed' => ['confirmed'],
    'preparing' => ['preparing'],
    'ready_for_pickup' => ['ready_for_pickup'],
    'completed' => ['completed'],
    'cancelled' => ['cancelled'],
]);

test('confirming an order sets confirmed_at', function () {
    $staff = User::factory()->staff()->create();
    $order = Order::factory()->create([
        'is_non_prescription' => true,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", [
            'status' => 'confirmed',
        ])
        ->assertSuccessful();

    expect($order->fresh()->confirmed_at)->not->toBeNull();
});

test('completing an order sets completed_at', function () {
    $staff = User::factory()->staff()->create();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => true,
        'order_status_id' => $confirmedStatus->id,
        'confirmed_at' => now(),
    ]);

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", [
            'status' => 'completed',
        ])
        ->assertSuccessful();

    expect($order->fresh()->completed_at)->not->toBeNull();
});

test('prescription orders cannot be confirmed without a customer prescription', function () {
    $staff = User::factory()->staff()->create();
    $order = Order::factory()->create([
        'is_non_prescription' => false,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", [
            'status' => 'confirmed',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('prescription orders can be confirmed when the customer has a prescription', function () {
    $staff = User::factory()->staff()->create();
    $order = Order::factory()->create([
        'is_non_prescription' => false,
    ]);

    Prescription::factory()->create([
        'customer_id' => $order->customer_id,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", [
            'status' => 'confirmed',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'confirmed');
});

test('non prescription orders can be confirmed without prescription data', function () {
    $staff = User::factory()->staff()->create();
    $order = Order::factory()->create([
        'is_non_prescription' => true,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", [
            'status' => 'confirmed',
        ])
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
            'status' => 'under_review',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'under_review');
});
