<?php

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
use Database\Seeders\InventoryMovementTypeSeeder;
use Database\Seeders\OrderStatusSeeder;
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

test('staff receives a notification when variant stock drops to or below low_stock_threshold', function () {
    $staff = User::factory()->staff()->create();
    User::factory()->admin()->create();

    $variant = ProductVariant::factory()->create([
        'stock_quantity' => 3,
        'low_stock_threshold' => 3,
    ]);

    $this->seed(OrderStatusSeeder::class);
    $order = Order::factory()->create([
        'order_status_id' => OrderStatus::query()->where('name', 'requested')->value('id'),
        'is_non_prescription' => true,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'quantity' => 1,
    ]);

    app(UpdateOrderStatus::class)->handle($order, 'confirmed');

    // Staff/admin should have received a low stock notification
    $notification = DatabaseNotification::query()
        ->where('notifiable_id', $staff->id)
        ->whereJsonContains('data->title', 'Low Stock Alert')
        ->first();

    expect($notification)->not->toBeNull();
});
