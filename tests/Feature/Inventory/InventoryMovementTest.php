<?php

use App\Actions\Inventory\RecordInventoryMovement;
use App\Actions\Orders\UpdateOrderStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use Database\Seeders\InventoryMovementTypeSeeder;
use Database\Seeders\OrderStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
    $this->seed(InventoryMovementTypeSeeder::class);
});

it('deducts stock and records an order_commitment movement when an order is confirmed', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $order = Order::factory()->create([
        'order_status_id' => $requestedStatus->id,
        'is_non_prescription' => true,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);

    app(UpdateOrderStatus::class)->handle($order, 'confirmed');

    expect($variant->fresh()->stock_quantity)->toBe(8);

    $this->assertDatabaseHas(InventoryMovement::class, [
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'quantity_change' => -2,
        'inventory_movement_type_id' => InventoryMovementType::query()->where('name', 'order_commitment')->value('id'),
    ]);
});

it('does not deduct stock more than once if confirmed twice (idempotent via transition guard)', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $order = Order::factory()->create([
        'order_status_id' => $requestedStatus->id,
        'is_non_prescription' => true,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);

    app(UpdateOrderStatus::class)->handle($order, 'confirmed');

    // Confirm is terminal — any re-confirm attempt is blocked by transition rules
    expect(fn () => app(UpdateOrderStatus::class)->handle($order->fresh(), 'confirmed'))
        ->toThrow(ValidationException::class);

    expect($variant->fresh()->stock_quantity)->toBe(8);
    expect(InventoryMovement::where('order_id', $order->id)->count())->toBe(1);
});

it('restores stock and records an order_reversal movement when a confirmed order is cancelled', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $order = Order::factory()->create([
        'order_status_id' => $confirmedStatus->id,
        'is_non_prescription' => true,
        'confirmed_at' => now(),
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'quantity' => 3,
    ]);

    app(UpdateOrderStatus::class)->handle($order, 'cancelled');

    expect($variant->fresh()->stock_quantity)->toBe(13);

    $this->assertDatabaseHas(InventoryMovement::class, [
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'quantity_change' => 3,
        'inventory_movement_type_id' => InventoryMovementType::query()->where('name', 'order_reversal')->value('id'),
    ]);
});

it('does not create reversal movements when a non-confirmed order is cancelled', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $order = Order::factory()->create([
        'order_status_id' => $requestedStatus->id,
        'is_non_prescription' => true,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);

    app(UpdateOrderStatus::class)->handle($order, 'cancelled');

    expect($variant->fresh()->stock_quantity)->toBe(10);
    expect(InventoryMovement::where('order_id', $order->id)->count())->toBe(0);
});

it('stock remains correct after movements from multiple items', function () {
    $variantA = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $variantB = ProductVariant::factory()->create(['stock_quantity' => 20]);

    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $order = Order::factory()->create([
        'order_status_id' => $requestedStatus->id,
        'is_non_prescription' => true,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_variant_id' => $variantA->id,
        'quantity' => 2,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_variant_id' => $variantB->id,
        'quantity' => 5,
    ]);

    app(UpdateOrderStatus::class)->handle($order, 'confirmed');

    expect($variantA->fresh()->stock_quantity)->toBe(8);
    expect($variantB->fresh()->stock_quantity)->toBe(15);

    $this->assertDatabaseHas(InventoryMovement::class, [
        'order_id' => $order->id,
        'product_variant_id' => $variantA->id,
        'quantity_change' => -2,
        'inventory_movement_type_id' => InventoryMovementType::query()->where('name', 'order_commitment')->value('id'),
    ]);

    $this->assertDatabaseHas(InventoryMovement::class, [
        'order_id' => $order->id,
        'product_variant_id' => $variantB->id,
        'quantity_change' => -5,
        'inventory_movement_type_id' => InventoryMovementType::query()->where('name', 'order_commitment')->value('id'),
    ]);
});

it('RecordInventoryMovement action records a movement and updates stock', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $order = Order::factory()->create(['is_non_prescription' => true]);

    app(RecordInventoryMovement::class)->handle(
        variant: $variant,
        orderId: $order->id,
        quantityChange: -3,
        type: 'order_commitment',
        notes: 'Deducted on confirmation.',
    );

    expect($variant->fresh()->stock_quantity)->toBe(7);

    $this->assertDatabaseHas(InventoryMovement::class, [
        'product_variant_id' => $variant->id,
        'order_id' => $order->id,
        'quantity_change' => -3,
        'inventory_movement_type_id' => InventoryMovementType::query()->where('name', 'order_commitment')->value('id'),
        'notes' => 'Deducted on confirmation.',
    ]);
});

it('RecordInventoryMovement throws when stock is insufficient and rolls back', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 2]);
    $order = Order::factory()->create(['is_non_prescription' => true]);

    expect(fn () => app(RecordInventoryMovement::class)->handle(
        variant: $variant,
        orderId: $order->id,
        quantityChange: -5,
        type: 'order_commitment',
    ))->toThrow(RuntimeException::class);

    // Stock must remain unchanged and no movement must be recorded
    expect($variant->fresh()->stock_quantity)->toBe(2);
    expect(InventoryMovement::where('product_variant_id', $variant->id)->count())->toBe(0);
});

it('deducts both frame and lens product variant stock on order confirmation', function () {
    $frameVariant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $lensVariant = ProductVariant::factory()->create(['stock_quantity' => 5]);

    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $order = Order::factory()->create([
        'order_status_id' => $requestedStatus->id,
        'is_non_prescription' => true,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_variant_id' => $frameVariant->id,
        'lens_product_variant_id' => $lensVariant->id,
        'quantity' => 1,
    ]);

    app(UpdateOrderStatus::class)->handle($order, 'confirmed');

    expect($frameVariant->fresh()->stock_quantity)->toBe(9)
        ->and($lensVariant->fresh()->stock_quantity)->toBe(4);

    $this->assertDatabaseHas(InventoryMovement::class, [
        'product_variant_id' => $lensVariant->id,
        'quantity_change' => -1,
    ]);
});

it('only deducts frame stock when no lens product variant is assigned', function () {
    $frameVariant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $order = Order::factory()->create([
        'order_status_id' => $requestedStatus->id,
        'is_non_prescription' => true,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_variant_id' => $frameVariant->id,
        'lens_product_variant_id' => null,
        'quantity' => 1,
    ]);

    app(UpdateOrderStatus::class)->handle($order, 'confirmed');

    expect($frameVariant->fresh()->stock_quantity)->toBe(9);
});

it('restores both frame and lens product variant stock when a confirmed order is cancelled', function () {
    $frameVariant = ProductVariant::factory()->create(['stock_quantity' => 9]);
    $lensVariant = ProductVariant::factory()->create(['stock_quantity' => 4]);

    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $order = Order::factory()->create([
        'order_status_id' => $confirmedStatus->id,
        'is_non_prescription' => true,
    ]);

    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_variant_id' => $frameVariant->id,
        'lens_product_variant_id' => $lensVariant->id,
        'quantity' => 1,
    ]);

    app(UpdateOrderStatus::class)->handle($order, 'cancelled');

    expect($frameVariant->fresh()->stock_quantity)->toBe(10)
        ->and($lensVariant->fresh()->stock_quantity)->toBe(5);

    $this->assertDatabaseHas(InventoryMovement::class, [
        'product_variant_id' => $lensVariant->id,
        'quantity_change' => 1,
    ]);
});
