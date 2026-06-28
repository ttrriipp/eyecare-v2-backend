<?php

use App\Actions\Billing\GenerateBillingForOrder;
use App\Actions\Orders\UpdateOrderStatus;
use App\Models\Billing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    $this->seed(BillingStatusSeeder::class);
});

it('generates a billing record from a confirmed order', function () {
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $customer = User::factory()->customer()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'order_status_id' => $confirmedStatus->id,
        'subtotal' => '350.00',
        'total_amount' => '350.00',
        'confirmed_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'unit_price' => '350.00',
        'quantity' => 1,
        'subtotal' => '350.00',
    ]);

    $billing = app(GenerateBillingForOrder::class)->handle($order);

    expect($billing)->toBeInstanceOf(Billing::class)
        ->and($billing->order_id)->toBe($order->id)
        ->and($billing->customer_id)->toBe($customer->id)
        ->and($billing->total_amount)->toBe('350.00')
        ->and($billing->balance_due)->toBe('350.00')
        ->and($billing->status->name)->toBe('issued')
        ->and($billing->issued_at)->not->toBeNull();

    $this->assertDatabaseHas(Billing::class, [
        'order_id' => $order->id,
        'customer_id' => $customer->id,
        'total_amount' => '350.00',
        'balance_due' => '350.00',
    ]);
});

it('billing total reflects order items sum', function () {
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();

    $order = Order::factory()->create([
        'order_status_id' => $confirmedStatus->id,
        'subtotal' => '200.00',
        'total_amount' => '200.00',
        'confirmed_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'unit_price' => '200.00',
        'quantity' => 1,
        'subtotal' => '200.00',
    ]);

    $billing = app(GenerateBillingForOrder::class)->handle($order);

    expect($billing->total_amount)->toBe('200.00')
        ->and($billing->balance_due)->toBe('200.00');
});

it('prevents generating a second billing for the same order', function () {
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();

    $order = Order::factory()->create([
        'order_status_id' => $confirmedStatus->id,
        'confirmed_at' => now(),
    ]);

    app(GenerateBillingForOrder::class)->handle($order);

    expect(fn () => app(GenerateBillingForOrder::class)->handle($order))
        ->toThrow(ValidationException::class);

    expect(Billing::where('order_id', $order->id)->count())->toBe(1);
});

it('rejects billing generation for non-confirmed orders', function (string $statusName) {
    $status = OrderStatus::query()->where('name', $statusName)->firstOrFail();

    $order = Order::factory()->create(['order_status_id' => $status->id]);

    expect(fn () => app(GenerateBillingForOrder::class)->handle($order))
        ->toThrow(ValidationException::class);

    expect(Billing::where('order_id', $order->id)->count())->toBe(0);
})->with(['requested' => ['requested'], 'cancelled' => ['cancelled']]);

it('the order model exposes a billing relationship', function () {
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();

    $order = Order::factory()->create([
        'order_status_id' => $confirmedStatus->id,
        'confirmed_at' => now(),
    ]);

    expect($order->billing)->toBeNull();

    app(GenerateBillingForOrder::class)->handle($order);

    expect($order->fresh()->billing)->toBeInstanceOf(Billing::class);
});

it('confirming an order automatically creates an issued billing', function () {
    $this->seed(PaymentMethodSeeder::class);

    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $customer = User::factory()->customer()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'order_status_id' => $requestedStatus->id,
        'subtotal' => '500.00',
        'total_amount' => '500.00',
        'is_non_prescription' => true,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'unit_price' => '500.00',
        'quantity' => 1,
        'subtotal' => '500.00',
        'lens_type_id' => null,
        'lens_type_name' => null,
        'lens_type_price' => null,
        'product_variant_id' => ProductVariant::factory()->create(['stock_quantity' => 10])->id,
    ]);

    app(UpdateOrderStatus::class)->handle($order, 'confirmed');

    $billing = $order->fresh()->billing;

    expect($billing)->toBeInstanceOf(Billing::class)
        ->and($billing->status->name)->toBe('issued')
        ->and($billing->total_amount)->toBe('500.00')
        ->and($billing->issued_at)->not->toBeNull();
});
