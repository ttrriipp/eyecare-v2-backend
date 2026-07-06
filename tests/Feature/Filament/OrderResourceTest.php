<?php

use App\Actions\Orders\UpdateOrderStatus;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\RelationManagers\ItemsRelationManager;
use App\Models\LensCategory;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\PaymentStatusSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(OrderStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    $this->seed(BillingStatusSeeder::class);
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

    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $processingStatus = OrderStatus::query()->where('name', 'processing')->firstOrFail();

    $confirmedOrder = Order::factory()->create([
        'order_status_id' => $confirmedStatus->id,
    ]);

    $processingOrder = Order::factory()->create([
        'order_status_id' => $processingStatus->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$confirmedOrder, $processingOrder])
        ->set('activeTab', 'confirmed')
        ->assertCanSeeTableRecords([$confirmedOrder])
        ->assertCanNotSeeTableRecords([$processingOrder]);
});

test('staff can update order notes via the edit form', function () {
    $staff = User::factory()->staff()->create();

    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();

    $order = Order::factory()->create([
        'order_status_id' => $confirmedStatus->id,
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

test('processing action transitions confirmed non-prescription order to processing and deducts inventory', function () {
    $staff = User::factory()->staff()->create();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $processingStatus = OrderStatus::query()->where('name', 'processing')->firstOrFail();

    $order = Order::factory()->create([
        'order_status_id' => $confirmedStatus->id,
        'is_non_prescription' => true,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->fillForm(['order_status_id' => $processingStatus->id])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    expect($order->fresh()->status->name)->toBe('processing');
});

test('processing fails for prescription order without prescription', function () {
    $staff = User::factory()->staff()->create();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $processingStatus = OrderStatus::query()->where('name', 'processing')->firstOrFail();
    $customer = User::factory()->customer()->create();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'order_status_id' => $confirmedStatus->id,
        'is_non_prescription' => false,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->fillForm(['order_status_id' => $processingStatus->id])
        ->call('save')
        ->assertNotified();

    // Status should remain confirmed — processing sends a danger notification
    expect($order->fresh()->status->name)->toBe('confirmed');
});

test('cancel action transitions order to cancelled', function () {
    $staff = User::factory()->staff()->create();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $cancelledStatus = OrderStatus::query()->where('name', 'cancelled')->firstOrFail();

    $order = Order::factory()->create(['order_status_id' => $confirmedStatus->id]);

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
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $completedStatus = OrderStatus::query()->where('name', 'completed')->firstOrFail();

    $order = Order::factory()->create(['order_status_id' => $confirmedStatus->id]);

    $this->actingAs($staff);

    // Jump from confirmed → completed (skipping processing and ready_for_pickup) should fail
    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->fillForm(['order_status_id' => $completedStatus->id])
        ->call('save')
        ->assertHasFormErrors(['order_status_id']);

    expect($order->fresh()->status->name)->toBe('confirmed');
});

test('complete and cancel actions are hidden for completed orders', function () {
    $staff = User::factory()->staff()->create();
    $completedStatus = OrderStatus::query()->where('name', 'completed')->firstOrFail();

    $order = Order::factory()->create(['order_status_id' => $completedStatus->id]);

    $this->actingAs($staff);

    // Completed order status select has no options to transition to
    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()]);
    expect($order->fresh()->status->name)->toBe('completed');
});

test('staff can create an order with items and price snapshot, starting at confirmed', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $variant = ProductVariant::factory()->create(['price' => '150.00']);

    $this->actingAs($staff);

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'is_non_prescription' => true,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
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
        ->and($order->status->name)->toBe('confirmed')
        ->and($order->items)->toHaveCount(1)
        ->and($order->items->first()->unit_price)->toBe('150.00')
        ->and($order->items->first()->subtotal)->toBe('300.00');
});

test('creating an order does not attempt a redundant confirmed to confirmed transition', function () {
    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $variant = ProductVariant::factory()->create(['price' => '150.00']);

    $this->actingAs($staff);

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'is_non_prescription' => true,
            'items' => [
                ['product_variant_id' => $variant->id, 'quantity' => 1],
            ],
        ])
        ->call('create')
        ->assertNotNotified('Order saved as requested');

    $order = Order::query()->where('customer_id', $customer->id)->firstOrFail();
    expect($order->status->name)->toBe('confirmed');
});

test('staff can create an order for a walk-in customer (no email or password)', function () {
    $staff = User::factory()->staff()->create();
    $walkIn = User::factory()->walkIn()->create(['phone' => '09171234567']);
    $variant = ProductVariant::factory()->create(['price' => '100.00']);

    $this->actingAs($staff);

    Livewire::test(CreateOrder::class)
        ->fillForm([
            'customer_id' => $walkIn->id,
            'is_non_prescription' => true,
            'items' => [
                [
                    'product_variant_id' => $variant->id,
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

test('create order form excludes lens type products from the product selector', function () {
    $lensCategory = LensCategory::factory()->create();
    $lensProduct = Product::factory()->create(['product_type' => 'lens', 'lens_category_id' => $lensCategory->id]);
    $lensVariant = ProductVariant::factory()->for($lensProduct)->create(['is_active' => true]);
    $frameVariant = ProductVariant::factory()->create();

    $selectableIds = ProductVariant::query()
        ->with('product')
        ->where('is_active', true)
        ->whereHas('product', fn ($q) => $q->whereIn('product_type', ['frame', 'general']))
        ->pluck('id');

    expect($selectableIds)->toContain($frameVariant->id)
        ->and($selectableIds)->not->toContain($lensVariant->id);
});

test('staff can assign a lens product variant to an order item while confirmed', function () {
    $staff = User::factory()->staff()->create();
    $lensCategory = LensCategory::factory()->create(['name' => 'progressive', 'price' => null]);
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $order = Order::factory()->create(['order_status_id' => $confirmedStatus->id]);
    $item = $order->items()->create([
        'product_variant_id' => ProductVariant::factory()->create()->id,
        'lens_category_id' => $lensCategory->id,
        'product_id' => Product::factory()->create()->id,
        'product_name' => 'Frame',
        'variant_name' => 'Black',
        'variant_sku' => 'SKU-001',
        'lens_category_name' => 'progressive',
        'unit_price' => '3000.00',
        'quantity' => 1,
        'subtotal' => '3000.00',
    ]);

    $lensProduct = Product::factory()->create([
        'product_type' => 'lens',
        'lens_category_id' => $lensCategory->id,
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

test('assigning a lens product variant updates item lens price and order total', function () {
    $staff = User::factory()->staff()->create();
    $lensCategory = LensCategory::factory()->create(['name' => 'progressive', 'price' => 6500.00]);
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();

    $order = Order::factory()->create(['order_status_id' => $confirmedStatus->id, 'subtotal' => '3000.00', 'total_amount' => '9500.00']);
    $item = $order->items()->create([
        'product_variant_id' => ProductVariant::factory()->create()->id,
        'lens_category_id' => $lensCategory->id,
        'product_id' => Product::factory()->create()->id,
        'product_name' => 'Frame',
        'variant_name' => 'Black',
        'variant_sku' => 'SKU-001',
        'lens_category_name' => 'progressive',
        'unit_price' => '3000.00',
        'lens_type_price' => '6500.00',
        'quantity' => 1,
        'subtotal' => '9500.00',
    ]);

    $lensProduct = Product::factory()->create(['product_type' => 'lens', 'lens_category_id' => $lensCategory->id]);
    $lensVariant = ProductVariant::factory()->for($lensProduct)->create(['is_active' => true, 'price' => '7500.00']);

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

    // Item lens price updated to lens variant price
    expect($item->fresh()->lens_type_price)->toBe('7500.00')
        ->and($item->fresh()->subtotal)->toBe('10500.00');

    // Order subtotal and total recalculated (no discount at order level anymore)
    expect($order->fresh()->subtotal)->toBe('10500.00')
        ->and($order->fresh()->total_amount)->toBe('10500.00');
});

test('lens assignment is not available once order reaches processing', function () {
    $staff = User::factory()->staff()->create();
    $lensCategory = LensCategory::factory()->create(['name' => 'progressive', 'price' => null]);
    $processingStatus = OrderStatus::query()->where('name', 'processing')->firstOrFail();
    $order = Order::factory()->create(['order_status_id' => $processingStatus->id]);
    $item = $order->items()->create([
        'product_variant_id' => ProductVariant::factory()->create()->id,
        'lens_category_id' => $lensCategory->id,
        'product_id' => Product::factory()->create()->id,
        'product_name' => 'Frame',
        'variant_name' => 'Black',
        'variant_sku' => 'SKU-001',
        'lens_category_name' => 'progressive',
        'unit_price' => '3000.00',
        'quantity' => 1,
        'subtotal' => '3000.00',
    ]);

    $this->actingAs($staff);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $order,
        'pageClass' => EditOrder::class,
    ])->assertActionHidden(TestAction::make('assignLens')->table($item));
});

test('confirmed order edit page shows view billing action once processing generates a billing', function () {
    $this->seed(PaymentStatusSeeder::class);
    $this->seed(PaymentMethodSeeder::class);

    $staff = User::factory()->staff()->create();
    $customer = User::factory()->customer()->create();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'order_status_id' => $confirmedStatus->id,
        'total_amount' => '400.00',
        'is_non_prescription' => true,
    ]);

    $this->actingAs($staff);

    app(UpdateOrderStatus::class)->handle($order, 'processing');

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionVisible('view_billing');
});

test('view billing action is hidden when order has no billing', function () {
    $staff = User::factory()->staff()->create();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();

    $order = Order::factory()->create(['order_status_id' => $confirmedStatus->id]);

    $this->actingAs($staff);

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionHidden('view_billing');
});

test('edit order page renders successfully for a confirmed order with a frame item', function () {
    $staff = User::factory()->staff()->create();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $frameProduct = Product::factory()->create(['product_type' => 'frame']);
    $frameVariant = ProductVariant::factory()->for($frameProduct)->create();

    $order = Order::factory()->create(['order_status_id' => $confirmedStatus->id]);
    $order->items()->create([
        'product_variant_id' => $frameVariant->id,
        'product_id' => $frameProduct->id,
        'product_name' => $frameProduct->name,
        'variant_name' => $frameVariant->name,
        'variant_sku' => $frameVariant->sku,
        'unit_price' => $frameVariant->price,
        'quantity' => 1,
        'subtotal' => $frameVariant->price,
        'is_frame' => true,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->assertSuccessful();
});

test('edit order page renders successfully for a general (non-frame) item', function () {
    $staff = User::factory()->staff()->create();
    $confirmedStatus = OrderStatus::query()->where('name', 'confirmed')->firstOrFail();
    $generalProduct = Product::factory()->create(['product_type' => 'general']);
    $generalVariant = ProductVariant::factory()->for($generalProduct)->create();

    $order = Order::factory()->create(['order_status_id' => $confirmedStatus->id]);
    $order->items()->create([
        'product_variant_id' => $generalVariant->id,
        'product_id' => $generalProduct->id,
        'product_name' => $generalProduct->name,
        'variant_name' => $generalVariant->name,
        'variant_sku' => $generalVariant->sku,
        'unit_price' => $generalVariant->price,
        'quantity' => 1,
        'subtotal' => $generalVariant->price,
        'is_frame' => false,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->assertSuccessful();
});
