<?php

use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Models\Billing;
use App\Models\BillingStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\OrderStatusSeeder;
use Database\Seeders\PaymentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
    $this->seed(PaymentStatusSeeder::class);
    BillingStatus::query()->firstOrCreate(['name' => 'issued']);
    BillingStatus::query()->firstOrCreate(['name' => 'partially_paid']);
    BillingStatus::query()->firstOrCreate(['name' => 'paid']);
    $this->actingAs(User::factory()->admin()->create());
});

test('Collect Payment action is visible when order has billing with balance', function () {
    $order = Order::factory()->create([
        'order_status_id' => OrderStatus::query()->where('name', 'confirmed')->value('id'),
    ]);
    $billing = Billing::factory()->issued()->create([
        'customer_id' => $order->customer_id,
        'order_id' => $order->id,
        'total_amount' => '2500.00',
        'balance_due' => '2500.00',
    ]);

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionVisible('collect_payment');
});

test('Collect Payment action is hidden when no billing exists', function () {
    $order = Order::factory()->create([
        'order_status_id' => OrderStatus::query()->where('name', 'requested')->value('id'),
    ]);

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionHidden('collect_payment');
});

test('Collect Payment records payment against the billing', function () {
    $this->seed(PaymentStatusSeeder::class);

    $cashMethod = PaymentMethod::query()->firstOrCreate(['name' => 'Cash']);
    $order = Order::factory()->create([
        'order_status_id' => OrderStatus::query()->where('name', 'confirmed')->value('id'),
    ]);
    $billing = Billing::factory()->issued()->create([
        'customer_id' => $order->customer_id,
        'order_id' => $order->id,
        'total_amount' => '2500.00',
        'balance_due' => '2500.00',
        'amount_paid' => '0.00',
    ]);

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('collect_payment', [
            'amount' => 2500.00,
            'payment_method_id' => $cashMethod->id,
            'reference_number' => null,
        ])
        ->assertNotified();

    expect($billing->fresh()->status->name)->toBe('paid')
        ->and((float) $billing->fresh()->amount_paid)->toBe(2500.0)
        ->and((float) $billing->fresh()->balance_due)->toBe(0.0);
});

test('Partial payment leaves billing as partially_paid', function () {
    $cashMethod = PaymentMethod::query()->firstOrCreate(['name' => 'Cash']);
    $order = Order::factory()->create([
        'order_status_id' => OrderStatus::query()->where('name', 'confirmed')->value('id'),
    ]);
    $billing = Billing::factory()->issued()->create([
        'customer_id' => $order->customer_id,
        'order_id' => $order->id,
        'total_amount' => '2500.00',
        'balance_due' => '2500.00',
        'amount_paid' => '0.00',
    ]);

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('collect_payment', [
            'amount' => 1000.00,
            'payment_method_id' => $cashMethod->id,
            'reference_number' => null,
        ])
        ->assertNotified();

    expect($billing->fresh()->status->name)->toBe('partially_paid');
});
