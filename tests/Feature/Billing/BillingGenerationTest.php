<?php

use App\Actions\Billing\GenerateBillingForOrder;
use App\Models\Billing;
use App\Models\Order;
use App\Models\OrderStatus;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
    $this->seed(BillingStatusSeeder::class);
});

it('generates a billing record from a confirmed order', function () {
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();

    $order = Order::factory()->create([
        'order_status_id' => $confirmedStatus->id,
        'total_amount' => '350.00',
        'confirmed_at' => now(),
    ]);

    $billing = app(GenerateBillingForOrder::class)->handle($order);

    expect($billing)->toBeInstanceOf(Billing::class)
        ->and($billing->order_id)->toBe($order->id)
        ->and($billing->total_amount)->toBe('350.00')
        ->and($billing->balance_due)->toBe('350.00')
        ->and($billing->status->name)->toBe('draft');

    $this->assertDatabaseHas(Billing::class, [
        'order_id' => $order->id,
        'total_amount' => '350.00',
        'balance_due' => '350.00',
    ]);
});

it('billing total and initial balance match the order total_amount snapshot', function () {
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();

    $order = Order::factory()->create([
        'order_status_id' => $confirmedStatus->id,
        'subtotal' => '200.00',
        'total_amount' => '220.00',
        'discount_amount' => '0.00',
        'confirmed_at' => now(),
    ]);

    $billing = app(GenerateBillingForOrder::class)->handle($order);

    expect($billing->total_amount)->toBe('220.00')
        ->and($billing->balance_due)->toBe('220.00');
});

it('prevents generating a second billing for the same order', function () {
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();

    $order = Order::factory()->create([
        'order_status_id' => $confirmedStatus->id,
        'total_amount' => '150.00',
        'confirmed_at' => now(),
    ]);

    app(GenerateBillingForOrder::class)->handle($order);

    expect(fn () => app(GenerateBillingForOrder::class)->handle($order))
        ->toThrow(ValidationException::class);

    expect(Billing::where('order_id', $order->id)->count())->toBe(1);
});

it('rejects billing generation for non-confirmed orders', function (string $statusName) {
    $status = OrderStatus::query()->where('name', $statusName)->firstOrFail();

    $order = Order::factory()->create([
        'order_status_id' => $status->id,
    ]);

    expect(fn () => app(GenerateBillingForOrder::class)->handle($order))
        ->toThrow(ValidationException::class);

    expect(Billing::where('order_id', $order->id)->count())->toBe(0);
})->with([
    'requested' => ['requested'],

    'cancelled' => ['cancelled'],
]);

it('the order model exposes a billing relationship', function () {
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();

    $order = Order::factory()->create([
        'order_status_id' => $confirmedStatus->id,
        'total_amount' => '100.00',
        'confirmed_at' => now(),
    ]);

    expect($order->billing)->toBeNull();

    app(GenerateBillingForOrder::class)->handle($order);

    expect($order->fresh()->billing)->toBeInstanceOf(Billing::class);
});
