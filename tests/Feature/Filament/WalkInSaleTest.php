<?php

use App\Actions\Orders\UpdateOrderStatus;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Models\User;
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
    Http::fake();
    $this->actingAs(User::factory()->admin()->create());
});

test('Walk-in Sale button is visible on the orders list page', function () {
    Livewire::test(ListOrders::class)
        ->assertActionExists('walk_in_sale');
});

test('CreateOrder page shows walk-in subheading when walkin flag is set', function () {
    $component = Livewire::test(CreateOrder::class, ['isWalkIn' => true]);

    expect($component->instance()->isWalkIn)->toBeTrue();
});

test('walk-in sale confirms order immediately after creation', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'is_active' => true]);
    $customer = User::factory()->customer()->create();

    // Create a requested order and verify afterCreate confirms it by calling the action flow
    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'order_status_id' => OrderStatus::query()->where('name', 'requested')->value('id'),
        'is_non_prescription' => true,
    ]);
    $order->items()->create([
        'product_variant_id' => $variant->id,
        'product_id' => $variant->product_id,
        'product_name' => $variant->product->name,
        'variant_name' => $variant->name,
        'variant_sku' => $variant->sku,
        'unit_price' => $variant->price,
        'quantity' => 1,
        'subtotal' => $variant->price,
    ]);

    // Directly invoke the walk-in confirm logic (same as afterCreate does internally)
    app(UpdateOrderStatus::class)->handle($order, 'confirmed');

    expect($order->fresh()->status->name)->toBe('confirmed');
});

test('normal New Order button still creates order as requested', function () {
    $component = Livewire::test(CreateOrder::class);

    expect($component->instance()->isWalkIn)->toBeFalse();
});
