<?php

use App\Actions\Orders\ApplyDiscount;
use App\Actions\Orders\UpdateOrderStatus;
use App\Models\DiscountType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\DiscountTypeSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    $this->seed(DiscountTypeSeeder::class);
    $this->seed(BillingStatusSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
});

// ─── ApplyDiscount action ─────────────────────────────────────────────────────

it('applies a percentage discount and recalculates total_amount', function () {
    $discountType = DiscountType::factory()->percentage(20)->create(['name' => 'Test20']);
    $order = Order::factory()->create(['subtotal' => '100.00', 'total_amount' => '100.00']);

    app(ApplyDiscount::class)->handle($order, $discountType->id);

    $order->refresh();
    expect($order->discount_amount)->toBe('20.00')
        ->and($order->total_amount)->toBe('80.00')
        ->and($order->discount_type_id)->toBe($discountType->id);
});

it('applies a custom fixed discount using the provided amount', function () {
    $customType = DiscountType::query()->where('name', 'Custom')->firstOrFail();
    $order = Order::factory()->create(['subtotal' => '200.00', 'total_amount' => '200.00']);

    app(ApplyDiscount::class)->handle($order, $customType->id, 50.0);

    $order->refresh();
    expect($order->discount_amount)->toBe('50.00')
        ->and($order->total_amount)->toBe('150.00');
});

it('rejects a discount that exceeds the subtotal', function () {
    $discountType = DiscountType::factory()->fixed(999)->create(['name' => 'Huge']);
    $order = Order::factory()->create(['subtotal' => '100.00', 'total_amount' => '100.00']);

    expect(fn () => app(ApplyDiscount::class)->handle($order, $discountType->id))
        ->toThrow(ValidationException::class);

    expect($order->fresh()->discount_amount)->toBe('0.00');
});

it('rejects an inactive discount type', function () {
    $inactive = DiscountType::factory()->inactive()->create(['name' => 'Old']);
    $order = Order::factory()->create(['subtotal' => '100.00', 'total_amount' => '100.00']);

    expect(fn () => app(ApplyDiscount::class)->handle($order, $inactive->id))
        ->toThrow(ValidationException::class);
});

// ─── UpdateOrderStatus with discount ─────────────────────────────────────────

it('applies discount when confirming an order via the action', function () {
    $seniorDiscount = DiscountType::query()->where('name', 'Senior Citizen')->firstOrFail();
    $order = Order::factory()->create([
        'subtotal' => '100.00',
        'total_amount' => '100.00',
        'order_status_id' => $this->requestedStatus->id,
        'is_non_prescription' => true,
    ]);

    app(UpdateOrderStatus::class)->handle(
        order: $order,
        statusName: 'confirmed',
        discountTypeId: $seniorDiscount->id,
    );

    $order->refresh();
    expect($order->status->name)->toBe('confirmed')
        ->and($order->discount_amount)->toBe('20.00')
        ->and($order->total_amount)->toBe('80.00');
});

it('confirms without discount when no discount_type_id given', function () {
    $order = Order::factory()->create([
        'subtotal' => '100.00',
        'total_amount' => '100.00',
        'order_status_id' => $this->requestedStatus->id,
        'is_non_prescription' => true,
    ]);

    app(UpdateOrderStatus::class)->handle(order: $order, statusName: 'confirmed');

    $order->refresh();
    expect($order->discount_amount)->toBe('0.00')
        ->and($order->total_amount)->toBe('100.00');
});

// ─── API ─────────────────────────────────────────────────────────────────────

it('staff can confirm an order with a percentage discount via API', function () {
    $seniorDiscount = DiscountType::query()->where('name', 'Senior Citizen')->firstOrFail();
    $order = Order::factory()->create([
        'subtotal' => '200.00',
        'total_amount' => '200.00',
        'order_status_id' => $this->requestedStatus->id,
        'is_non_prescription' => true,
    ]);

    $this->actingAs($this->staff, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", [
            'status' => 'confirmed',
            'discount_type_id' => $seniorDiscount->id,
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'confirmed');

    $order->refresh();
    expect($order->discount_amount)->toBe('40.00')
        ->and($order->total_amount)->toBe('160.00');
});

it('staff can confirm an order with a custom discount amount via API', function () {
    $customType = DiscountType::query()->where('name', 'Custom')->firstOrFail();
    $order = Order::factory()->create([
        'subtotal' => '300.00',
        'total_amount' => '300.00',
        'order_status_id' => $this->requestedStatus->id,
        'is_non_prescription' => true,
    ]);

    $this->actingAs($this->staff, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", [
            'status' => 'confirmed',
            'discount_type_id' => $customType->id,
            'custom_discount_amount' => 75,
        ])
        ->assertSuccessful();

    $order->refresh();
    expect($order->discount_amount)->toBe('75.00')
        ->and($order->total_amount)->toBe('225.00');
});

it('API rejects a discount that would exceed the subtotal', function () {
    $customType = DiscountType::query()->where('name', 'Custom')->firstOrFail();
    $order = Order::factory()->create([
        'subtotal' => '50.00',
        'total_amount' => '50.00',
        'order_status_id' => $this->requestedStatus->id,
        'is_non_prescription' => true,
    ]);

    $this->actingAs($this->staff, 'sanctum')
        ->patchJson("/api/staff/orders/{$order->id}/status", [
            'status' => 'confirmed',
            'discount_type_id' => $customType->id,
            'custom_discount_amount' => 999,
        ])
        ->assertUnprocessable();

    expect($order->fresh()->status->name)->toBe('requested');
});

// ─── Billing uses discounted total ───────────────────────────────────────────

it('billing is generated with the discounted total_amount', function () {
    $seniorDiscount = DiscountType::query()->where('name', 'Senior Citizen')->firstOrFail();
    $order = Order::factory()->create([
        'subtotal' => '100.00',
        'total_amount' => '100.00',
        'order_status_id' => $this->requestedStatus->id,
        'is_non_prescription' => true,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'unit_price' => '100.00',
        'quantity' => 1,
        'subtotal' => '100.00',
        'lens_type_id' => null,
        'lens_type_name' => null,
        'lens_type_price' => null,
        'product_variant_id' => ProductVariant::factory()->create(['stock_quantity' => 10])->id,
    ]);

    app(UpdateOrderStatus::class)->handle(
        order: $order,
        statusName: 'confirmed',
        discountTypeId: $seniorDiscount->id,
    );

    $billing = $order->fresh()->billing;
    expect($billing->total_amount)->toBe('80.00')
        ->and($billing->balance_due)->toBe('80.00');
});
