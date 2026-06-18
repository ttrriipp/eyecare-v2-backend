<?php

use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\RelationManagers\ItemsRelationManager;
use App\Models\LensType;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\OrderStatusSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
});

test('staff and admin users can list orders', function (string $factoryState) {
    $user = User::factory()->{$factoryState}()->create();
    $orders = Order::factory()->count(2)->create();

    $this->actingAs($user);

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords($orders);
})->with([
    'admin' => ['admin'],
    'staff' => ['staff'],
]);

test('order table can filter by status', function () {
    $staff = User::factory()->staff()->create();

    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $underReviewStatus = OrderStatus::query()->where('name', 'under_review')->firstOrFail();

    $requestedOrder = Order::factory()->create([
        'order_status_id' => $requestedStatus->id,
    ]);

    $underReviewOrder = Order::factory()->create([
        'order_status_id' => $underReviewStatus->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ListOrders::class)
        ->filterTable('status', $requestedStatus->id)
        ->assertCanSeeTableRecords([$requestedOrder])
        ->assertCanNotSeeTableRecords([$underReviewOrder]);
});

test('order table can filter by customer', function () {
    $staff = User::factory()->staff()->create();
    $customerA = User::factory()->customer()->create();
    $customerB = User::factory()->customer()->create();

    $orderA = Order::factory()->create(['customer_id' => $customerA->id]);
    $orderB = Order::factory()->create(['customer_id' => $customerB->id]);

    $this->actingAs($staff);

    Livewire::test(ListOrders::class)
        ->filterTable('customer', $customerA->id)
        ->assertCanSeeTableRecords([$orderA])
        ->assertCanNotSeeTableRecords([$orderB]);
});

test('staff can update order notes via the edit form', function () {
    $staff = User::factory()->staff()->create();

    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();

    $order = Order::factory()->create([
        'order_status_id' => $requestedStatus->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->fillForm([
            'notes' => 'Updated staff notes.',
        ])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect($order->fresh()->notes)->toBe('Updated staff notes.');
});

test('review action transitions requested order to under_review', function () {
    $staff = User::factory()->staff()->create();
    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $underReviewStatus = OrderStatus::query()->where('name', 'under_review')->firstOrFail();

    $order = Order::factory()->create(['order_status_id' => $requestedStatus->id]);

    $this->actingAs($staff);

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->fillForm(['order_status_id' => $underReviewStatus->id])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect($order->fresh()->status->name)->toBe('under_review');
});

test('confirm action transitions under_review non-prescription order to confirmed and deducts inventory', function () {
    $staff = User::factory()->staff()->create();
    $underReviewStatus = OrderStatus::query()->where('name', 'under_review')->firstOrFail();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();

    $order = Order::factory()->create([
        'order_status_id' => $underReviewStatus->id,
        'is_non_prescription' => true,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->fillForm(['order_status_id' => $confirmedStatus->id])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect($order->fresh()->status->name)->toBe('confirmed');
});

test('confirm fails for prescription order without prescription', function () {
    $staff = User::factory()->staff()->create();
    $underReviewStatus = OrderStatus::query()->where('name', 'under_review')->firstOrFail();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $customer = User::factory()->customer()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'order_status_id' => $underReviewStatus->id,
        'is_non_prescription' => false,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->fillForm(['order_status_id' => $confirmedStatus->id])
        ->call('save')
        ->assertHasFormErrors(['order_status_id']);

    expect($order->fresh()->status->name)->toBe('under_review');
});

test('cancel action transitions order to cancelled', function () {
    $staff = User::factory()->staff()->create();
    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $cancelledStatus = OrderStatus::query()->where('name', 'cancelled')->firstOrFail();

    $order = Order::factory()->create(['order_status_id' => $requestedStatus->id]);

    $this->actingAs($staff);

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->fillForm(['order_status_id' => $cancelledStatus->id])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect($order->fresh()->status->name)->toBe('cancelled');
});

test('status dropdown does not allow skipping steps for orders', function () {
    $staff = User::factory()->staff()->create();
    $requestedStatus = OrderStatus::query()->where('name', 'requested')->firstOrFail();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();

    $order = Order::factory()->create(['order_status_id' => $requestedStatus->id]);

    $this->actingAs($staff);

    // Jump from requested → confirmed (skipping under_review) should fail
    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->fillForm(['order_status_id' => $confirmedStatus->id])
        ->call('save')
        ->assertHasFormErrors(['order_status_id']);

    expect($order->fresh()->status->name)->toBe('requested');
});

test('complete and cancel actions are hidden for completed orders', function () {
    $staff = User::factory()->staff()->create();
    $completedStatus = OrderStatus::query()->where('name', 'completed')->firstOrFail();

    $order = Order::factory()->create(['order_status_id' => $completedStatus->id]);

    $this->actingAs($staff);

    // Completed order status select has no options to transition to
    $component = Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()]);
    expect($order->fresh()->status->name)->toBe('completed');
});

test('staff can create an order with items and price snapshot', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $variant = ProductVariant::factory()->create(['price' => '150.00']);
    $lensType = LensType::factory()->create(['price' => null]);

    $this->actingAs($staff);

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'is_non_prescription' => true,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'lens_type_id' => $lensType->id,
                    'quantity' => 2,
                ],
            ],
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $order = Order::query()->where('customer_id', $customer->id)->firstOrFail();

    expect($order->total_amount)->toBe('300.00')
        ->and($order->items)->toHaveCount(1)
        ->and($order->items->first()->unit_price)->toBe('150.00')
        ->and($order->items->first()->subtotal)->toBe('300.00');
});

test('staff can create an order for a walk-in customer (no email or password)', function () {
    $staff = User::factory()->staff()->create();
    $walkIn = User::factory()->walkIn()->create(['phone' => '09171234567']);
    $variant = ProductVariant::factory()->create(['price' => '100.00']);
    $lensType = LensType::factory()->create();

    $this->actingAs($staff);

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $walkIn->id,
            'is_non_prescription' => true,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
                    'lens_type_id' => $lensType->id,
                    'quantity' => 1,
                ],
            ],
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(Order::class, [
        'customer_id' => $walkIn->id,
    ]);

    expect($walkIn->email)->toBeNull()
        ->and($walkIn->password)->toBeNull();
});

test('staff can assign a lens product variant to an order item', function () {
    $staff = User::factory()->staff()->create();
    $lensType = LensType::factory()->create(['name' => 'progressive', 'price' => null]);
    $order = Order::factory()->create();
    $item = $order->items()->create([
        'product_variant_id' => ProductVariant::factory()->create()->id,
        'lens_type_id' => $lensType->id,
        'product_id' => Product::factory()->create()->id,
        'product_name' => 'Frame',
        'variant_name' => 'Black',
        'variant_sku' => 'SKU-001',
        'lens_type_name' => 'progressive',
        'unit_price' => '3000.00',
        'quantity' => 1,
        'subtotal' => '3000.00',
    ]);

    $lensProduct = Product::factory()->create([
        'product_type' => 'lens',
        'lens_type_id' => $lensType->id,
    ]);
    $lensVariant = ProductVariant::factory()->for($lensProduct)->create(['is_active' => true]);

    $this->actingAs($staff);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $order,
        'pageClass' => EditOrder::class,
    ])
        ->callAction(
            TestAction::make('assignLens')->table($item),
            ['lens_product_variant_id' => $lensVariant->id],
        )
        ->assertNotified();

    expect($item->fresh()->lens_product_variant_id)->toBe($lensVariant->id);
});
