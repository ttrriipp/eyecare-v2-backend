<?php

use App\Actions\Inventory\RecordInventoryMovement;
use App\Actions\Orders\UpdateOrderStatus;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\BillingStatusSeeder;
use Database\Seeders\InventoryMovementTypeSeeder;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\OrderStatusSeeder;
use Database\Seeders\PaymentStatusSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(InventoryMovementTypeSeeder::class);
});

test('staff can restock a variant via the variants relation manager', function () {
    $staff = User::factory()->staff()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create(['stock_quantity' => 5]);

    $this->actingAs($staff);

    Livewire::test(
        VariantsRelationManager::class,
        ['ownerRecord' => $product, 'pageClass' => EditProduct::class]
    )
        ->callAction(
            TestAction::make('adjustStock')->table($variant),
            ['type' => 'restock', 'quantity' => 10, 'notes' => 'Stock received'],
        )
        ->assertHasNoErrors();

    expect($variant->fresh()->stock_quantity)->toBe(15);

    $this->assertDatabaseHas(InventoryMovement::class, [
        'product_variant_id' => $variant->id,
        'quantity_change' => 10,
    ]);
});

test('restocking beyond the target stock level succeeds with a warning', function () {
    $staff = User::factory()->staff()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 5,
        'low_stock_threshold' => 3,
        'target_stock_level' => 10,
    ]);

    $this->actingAs($staff);

    Livewire::test(
        VariantsRelationManager::class,
        ['ownerRecord' => $product, 'pageClass' => EditProduct::class]
    )
        ->callAction(
            TestAction::make('adjustStock')->table($variant),
            ['type' => 'restock', 'quantity' => 10],
        )
        ->assertHasNoErrors()
        ->assertNotified('Stock exceeds target');

    expect($variant->fresh()->stock_quantity)->toBe(15);
});

test('restocking without a configured target does not show an over-target warning', function () {
    $staff = User::factory()->staff()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 5,
        'target_stock_level' => null,
    ]);

    $this->actingAs($staff);

    Livewire::test(
        VariantsRelationManager::class,
        ['ownerRecord' => $product, 'pageClass' => EditProduct::class]
    )
        ->callAction(
            TestAction::make('adjustStock')->table($variant),
            ['type' => 'restock', 'quantity' => 10],
        )
        ->assertHasNoErrors()
        ->assertNotified('Stock updated')
        ->assertNotNotified('Stock exceeds target');

    expect($variant->fresh()->stock_quantity)->toBe(15);
});

test('staff receives a notification when variant stock drops to or below low_stock_threshold', function () {
    $staff = User::factory()->staff()->create();
    User::factory()->admin()->create();

    $variant = ProductVariant::factory()->create([
        'stock_quantity' => 3,
        'low_stock_threshold' => 3,
    ]);

    $this->seed(OrderStatusSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    $this->seed(BillingStatusSeeder::class);
    $this->seed(PaymentStatusSeeder::class);
    $order = Order::factory()->create([
        'order_status_id' => OrderStatus::query()->where('name', 'confirmed')->value('id'),
        'is_non_prescription' => true,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'lens_category_id' => null,
        'lens_product_variant_id' => null,
        'quantity' => 1,
    ]);

    app(UpdateOrderStatus::class)->handle($order, 'processing');

    // Staff/admin should have received a low stock notification
    $notification = DatabaseNotification::query()
        ->where('notifiable_id', $staff->id)
        ->whereJsonContains('data->title', 'Low Stock Alert')
        ->first();

    expect($notification)->not->toBeNull();
});

test('inventory movement records previous_stock, new_stock, and created_by', function () {
    $this->seed(InventoryMovementTypeSeeder::class);

    $staff = User::factory()->staff()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $movement = app(RecordInventoryMovement::class)->handle(
        variant: $variant,
        quantityChange: 5,
        type: 'restock',
        actingUser: $staff,
    );

    expect($movement->previous_stock)->toBe(10)
        ->and($movement->new_stock)->toBe(15)
        ->and($movement->created_by)->toBe($staff->id);
});

test('admin can archive a variant via the variants relation manager', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();

    $this->actingAs($admin);

    Livewire::test(
        VariantsRelationManager::class,
        ['ownerRecord' => $product, 'pageClass' => EditProduct::class]
    )
        ->callAction(TestAction::make('delete')->table($variant));

    expect($variant->fresh()->trashed())->toBeTrue();
});

test('staff cannot archive a variant via the variants relation manager', function () {
    $staff = User::factory()->staff()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();

    $this->actingAs($staff);

    Livewire::test(
        VariantsRelationManager::class,
        ['ownerRecord' => $product, 'pageClass' => EditProduct::class]
    )
        ->assertTableActionHidden('delete', $variant);
});

test('admin can restore an archived variant via the variants relation manager', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $variant->delete();

    $this->actingAs($admin);

    Livewire::test(
        VariantsRelationManager::class,
        ['ownerRecord' => $product, 'pageClass' => EditProduct::class]
    )
        ->assertTableActionHidden('delete', $variant)
        ->assertTableActionVisible('restore', $variant)
        ->callAction(TestAction::make('restore')->table($variant));

    expect($variant->fresh()->trashed())->toBeFalse();
});

test('archived variants are hidden from the variants relation manager by default', function () {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create();
    $activeVariant = ProductVariant::factory()->for($product)->create();
    $archivedVariant = ProductVariant::factory()->for($product)->create();
    $archivedVariant->delete();

    $this->actingAs($admin);

    Livewire::test(
        VariantsRelationManager::class,
        ['ownerRecord' => $product, 'pageClass' => EditProduct::class]
    )
        ->assertCanSeeTableRecords([$activeVariant])
        ->assertCanNotSeeTableRecords([$archivedVariant]);
});
