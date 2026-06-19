<?php

use App\Actions\Orders\UpdateOrderStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Prescription;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
});

it('allows a valid transition and updates the order status', function () {
    $order = Order::factory()->create(['is_non_prescription' => true]);

    $action = new UpdateOrderStatus;
    $updated = $action->handle($order, 'confirmed');

    expect($updated->status->name)->toBe('confirmed');
    expect($order->fresh()->status->name)->toBe('confirmed');
});

it('rejects an invalid transition and throws a validation exception', function () {
    $order = Order::factory()->create(['is_non_prescription' => true]);

    $action = new UpdateOrderStatus;

    expect(fn () => $action->handle($order, 'completed'))
        ->toThrow(ValidationException::class);
});

it('sets confirmed_at when transitioning to confirmed', function () {
    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => true,
        'order_status_id' => $requestedStatus->id,
    ]);

    $action = new UpdateOrderStatus;
    $updated = $action->handle($order, 'confirmed');

    expect($updated->confirmed_at)->not->toBeNull();
    expect($order->fresh()->confirmed_at)->not->toBeNull();
});

it('sets completed_at when transitioning to completed', function () {
    $readyStatus = OrderStatus::query()->where('name', 'ready_for_pickup')->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => true,
        'order_status_id' => $readyStatus->id,
        'confirmed_at' => now(),
    ]);

    $action = new UpdateOrderStatus;
    $updated = $action->handle($order, 'completed');

    expect($updated->completed_at)->not->toBeNull();
});

it('blocks confirming a prescription order without a prescription on record', function () {
    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => false,
        'order_status_id' => $requestedStatus->id,
    ]);

    $action = new UpdateOrderStatus;

    expect(fn () => $action->handle($order, 'confirmed'))
        ->toThrow(ValidationException::class);
});

it('allows confirming a prescription order when the customer has a prescription', function () {
    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => false,
        'order_status_id' => $requestedStatus->id,
    ]);

    Prescription::factory()->create(['customer_id' => $order->customer_id]);

    $action = new UpdateOrderStatus;
    $updated = $action->handle($order, 'confirmed');

    expect($updated->status->name)->toBe('confirmed');
});

it('blocks transitions from terminal states', function (string $terminalStatus) {
    $status = OrderStatus::query()->where('name', $terminalStatus)->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => true,
        'order_status_id' => $status->id,
    ]);

    $action = new UpdateOrderStatus;

    expect(fn () => $action->handle($order, 'confirmed'))
        ->toThrow(ValidationException::class);
})->with([
    'completed' => ['completed'],
    'cancelled' => ['cancelled'],
]);
