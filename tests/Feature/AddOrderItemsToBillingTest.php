<?php

use App\Actions\Billing\AddOrderItemsToBilling;
use App\Models\Billing;
use App\Models\BillingItem;
use App\Models\DiscountType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\User;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\DiscountTypeSeeder;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(BillingStatusSeeder::class);
    $this->seed(OrderStatusSeeder::class);
});

it('creates product billing_items from order_items', function () {
    $customer = User::factory()->customer()->create();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $order = Order::factory()->create(['customer_id' => $customer->id, 'order_status_id' => $confirmedStatus->id, 'subtotal' => '200.00', 'total_amount' => '200.00']);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'unit_price' => '200.00',
        'quantity' => 1,
        'subtotal' => '200.00',
        'lens_type_price' => null,
    ]);

    $billing = Billing::factory()->issued()->create(['customer_id' => $customer->id, 'order_id' => null]);

    $result = app(AddOrderItemsToBilling::class)->handle($billing, $order);

    expect($result->items)->toHaveCount(1)
        ->and($result->order_id)->toBe($order->id)
        ->and($result->subtotal)->toBe('200.00')
        ->and($result->total_amount)->toBe('200.00');
});

it('preserves an existing billing-level discount when order items are added', function () {
    $this->seed(DiscountTypeSeeder::class);

    $customer = User::factory()->customer()->create();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $discount = DiscountType::query()->where('name', 'Senior Citizen')->firstOrFail();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'order_status_id' => $confirmedStatus->id,
        'subtotal' => '200.00',
        'total_amount' => '200.00',
    ]);

    OrderItem::factory()->create(['order_id' => $order->id, 'unit_price' => '200.00', 'quantity' => 1, 'subtotal' => '200.00']);

    // Discount is applied directly on the billing (not derived from the order)
    $billing = Billing::factory()->issued()->create([
        'customer_id' => $customer->id,
        'discount_type_id' => $discount->id,
        'discount_amount' => '40.00',
    ]);

    $result = app(AddOrderItemsToBilling::class)->handle($billing, $order);

    expect($result->discount_type_id)->toBe($discount->id)
        ->and($result->discount_amount)->toBe('40.00')
        ->and($result->total_amount)->toBe('160.00');
});

it('throws if order items already on this billing', function () {
    $customer = User::factory()->customer()->create();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $order = Order::factory()->create(['customer_id' => $customer->id, 'order_status_id' => $confirmedStatus->id]);
    $orderItem = OrderItem::factory()->create(['order_id' => $order->id, 'unit_price' => '100.00', 'quantity' => 1, 'subtotal' => '100.00']);

    $billing = Billing::factory()->issued()->create(['customer_id' => $customer->id]);

    BillingItem::factory()->create(['billing_id' => $billing->id, 'type' => 'product', 'order_item_id' => $orderItem->id]);

    expect(fn () => app(AddOrderItemsToBilling::class)->handle($billing, $order))
        ->toThrow(ValidationException::class);
});

it('does not overwrite an earlier order link when a second order shares the same billing', function () {
    $customer = User::factory()->customer()->create();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();

    $billing = Billing::factory()->issued()->create(['customer_id' => $customer->id, 'order_id' => null]);

    $firstOrder = Order::factory()->create(['customer_id' => $customer->id, 'order_status_id' => $confirmedStatus->id]);
    OrderItem::factory()->create(['order_id' => $firstOrder->id, 'unit_price' => '100.00', 'quantity' => 1, 'subtotal' => '100.00']);
    app(AddOrderItemsToBilling::class)->handle($billing, $firstOrder);

    $secondOrder = Order::factory()->create(['customer_id' => $customer->id, 'order_status_id' => $confirmedStatus->id]);
    OrderItem::factory()->create(['order_id' => $secondOrder->id, 'unit_price' => '50.00', 'quantity' => 1, 'subtotal' => '50.00']);
    $result = app(AddOrderItemsToBilling::class)->handle($billing->fresh(), $secondOrder);

    // The billing still resolves back to the first order via billing()/order_id —
    // the second order's items are present via billing_items.order_item_id, but
    // adding them must not clobber the first order's link to this billing.
    expect($result->order_id)->toBe($firstOrder->id)
        ->and($firstOrder->fresh()->billing?->id)->toBe($billing->id)
        ->and($result->items)->toHaveCount(2)
        ->and($result->subtotal)->toBe('150.00');
});
