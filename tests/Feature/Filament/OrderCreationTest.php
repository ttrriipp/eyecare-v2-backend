<?php

use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Database\Seeders\PaymentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
    $this->seed(PaymentStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    $this->seed(BillingStatusSeeder::class);
    Http::fake();
    $this->actingAs(User::factory()->admin()->create());
});

test('only a single New Order button is visible on the orders list page', function () {
    Livewire::test(ListOrders::class)
        ->assertActionExists('create')
        ->assertActionDoesNotExist('walk_in_sale');
});

test('creating an order via the admin form confirms it immediately', function () {
    $customer = User::factory()->customer()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'is_active' => true]);

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'is_non_prescription' => true,
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 1],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $order = Order::query()->where('customer_id', $customer->id)->latest()->first();

    expect($order->status->name)->toBe('confirmed');
});

test('order stays confirmed (not processing) if lens gate would block it, without failing creation', function () {
    $customer = User::factory()->customer()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'is_active' => true]);

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'is_non_prescription' => false,
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 1],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $order = Order::query()->where('customer_id', $customer->id)->latest()->first();

    // Confirmation itself has no gates now — always succeeds. Gates apply at processing.
    expect($order->status->name)->toBe('confirmed');
});

test('order create form does not include lens fields', function () {
    Prescription::factory()->create();

    Livewire::test(CreateOrder::class)
        ->assertFormFieldDoesNotExist('items.0.lens_category_id');
});
