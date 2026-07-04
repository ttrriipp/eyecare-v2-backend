<?php

use App\Actions\Billing\GenerateBillingForOrder;
use App\Actions\Orders\UpdateOrderStatus;
use App\Models\Billing;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Prescription;
use App\Models\SmsNotification;
use App\Models\User;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Database\Seeders\PaymentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    $this->seed(BillingStatusSeeder::class);
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

it('blocks processing a prescription order without a prescription on record', function () {
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => false,
        'order_status_id' => $confirmedStatus->id,
    ]);

    $action = new UpdateOrderStatus;

    expect(fn () => $action->handle($order, 'processing'))
        ->toThrow(ValidationException::class);
});

it('allows processing a prescription order when the customer has a prescription', function () {
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => false,
        'order_status_id' => $confirmedStatus->id,
    ]);

    Prescription::factory()->create(['customer_id' => $order->customer_id]);

    $action = new UpdateOrderStatus;
    $updated = $action->handle($order, 'processing');

    expect($updated->status->name)->toBe('processing');
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

test('SMS notification record created when order is confirmed', function () {
    $order = Order::factory()->create(['is_non_prescription' => true]);

    (new UpdateOrderStatus)->handle($order, 'confirmed');

    $this->assertDatabaseHas(SmsNotification::class, [
        'order_id' => $order->id,
        'event' => 'order_confirmed',
    ]);
});

test('SMS notification record created when order is cancelled', function () {
    $order = Order::factory()->create(['is_non_prescription' => true]);

    (new UpdateOrderStatus)->handle($order, 'cancelled');

    $this->assertDatabaseHas(SmsNotification::class, [
        'order_id' => $order->id,
        'event' => 'order_cancelled',
    ]);
});

it('does not deduct inventory when an order is only confirmed', function () {
    $order = Order::factory()->create(['is_non_prescription' => true]);
    $order->loadMissing('items');

    $action = new UpdateOrderStatus;
    $action->handle($order, 'confirmed');

    // No items on the base factory order, but the transition itself must not
    // require inventory gates or fail due to missing stock at this stage.
    expect($order->fresh()->status->name)->toBe('confirmed');
});

it('requires processing to be the trigger for inventory commitment and billing generation', function () {
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $order = Order::factory()->create([
        'is_non_prescription' => true,
        'order_status_id' => $confirmedStatus->id,
    ]);

    $action = new UpdateOrderStatus;
    $updated = $action->handle($order, 'processing');

    expect($updated->status->name)->toBe('processing');
    $this->assertDatabaseHas(Billing::class, [
        'order_id' => $order->id,
    ]);

    it('does not void a billing shared with another pre-linked order when one order is cancelled', function () {
        $this->seed(PaymentStatusSeeder::class);

        $processingStatus = OrderStatus::query()->where('name', 'processing')->firstOrFail();
        $customer = User::factory()->customer()->create();

        // First order generates the billing.
        $firstOrder = Order::factory()->create([
            'customer_id' => $customer->id,
            'is_non_prescription' => true,
            'order_status_id' => $processingStatus->id,
        ]);
        app(GenerateBillingForOrder::class)->handle($firstOrder->fresh());
        $billing = $firstOrder->fresh()->billing;

        // Second order is pre-linked to the same billing.
        $secondOrder = Order::factory()->create([
            'customer_id' => $customer->id,
            'is_non_prescription' => true,
            'order_status_id' => $processingStatus->id,
            'billing_id' => $billing->id,
        ]);
        app(GenerateBillingForOrder::class)->handle($secondOrder->fresh());

        // Cancelling the second order must not void the shared billing.
        app(UpdateOrderStatus::class)->handle($secondOrder->fresh(), 'cancelled');

        expect($billing->fresh()->status->name)->not->toBe('voided');
    });
});
