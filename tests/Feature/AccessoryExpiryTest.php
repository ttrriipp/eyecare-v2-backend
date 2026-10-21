<?php

use App\Actions\Inventory\ReceiveContactLensStock;
use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Enums\CommercialItemKind;
use App\Enums\JobOrderStatus;
use App\Filament\Resources\Inventory\Pages\ListInventory;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Brand;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->actingAs(User::factory()->staff()->create());
});

test('the expiry requirement option is only shown for accessories', function (string $productType, bool $visible): void {
    $component = Livewire::test(CreateProduct::class)
        ->fillForm(['product_type' => $productType]);

    if ($visible) {
        $component->assertFormFieldVisible('requires_expiry');
    } else {
        $component->assertFormFieldHidden('requires_expiry');
    }
})->with([
    'frame' => ['frame', false],
    'contact lenses' => ['contact_lens', false],
    'accessories' => ['accessory', true],
]);

test('accessories save their selected expiry requirement during creation', function (bool $requiresExpiry): void {
    $brand = Brand::factory()->create();

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'product_type' => 'accessory',
            'name' => 'Accessory with expiry choice',
            'slug' => 'accessory-with-expiry-choice',
            'brand_id' => $brand->id,
            'requires_expiry' => $requiresExpiry,
            'is_active' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas(Product::class, [
        'slug' => 'accessory-with-expiry-choice',
        'requires_expiry' => $requiresExpiry,
    ]);
})->with([true, false]);

test('accessory expiry requirements can be changed when editing', function (bool $requiresExpiry): void {
    $product = Product::factory()->accessory()->inactive()->create();

    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->fillForm(['requires_expiry' => $requiresExpiry])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $this->assertDatabaseHas(Product::class, [
        'id' => $product->id,
        'requires_expiry' => $requiresExpiry,
    ]);
})->with([true, false]);

test('accessories without expiry can be received viewed and written off by lot', function (): void {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->accessory()->state(['requires_expiry' => false]))
        ->create(['stock_quantity' => 0]);

    Livewire::test(ListInventory::class)
        ->mountTableAction('adjustStock', $variant)
        ->assertMountedActionModalDontSee('Expiry month')
        ->fillForm([
            'quantity' => 5,
            'purchase_order_number' => 'PO-NON-EXPIRING',
            'lot_number' => 'CASE-001',
            'purchased_at' => today()->toDateString(),
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified();

    $lot = InventoryLot::query()->sole();

    expect($lot->expires_on)->toBeNull()
        ->and($variant->fresh()->stock_quantity)->toBe(5)
        ->and($variant->fresh()->usableStockQuantity())->toBe(5);

    Livewire::test(ListInventory::class)
        ->mountTableAction('viewBatches', $variant)
        ->assertMountedActionModalSee(['CASE-001', 'No expiry']);

    Livewire::test(ListInventory::class)
        ->callAction(TestAction::make('writeOffDamaged')->table($variant), [
            'quantity' => 2,
            'inventory_lot_id' => $lot->id,
            'damage_reason' => 'packaging_damaged',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($lot->fresh()->quantity_on_hand)->toBe(3)
        ->and($variant->fresh()->stock_quantity)->toBe(3);
});

test('expiry remains required for enabled accessories and contact lenses', function (string $productType, bool $requiresExpiry): void {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->state([
            'product_type' => $productType,
            'requires_expiry' => $requiresExpiry,
        ]))
        ->create(['stock_quantity' => 0]);

    Livewire::test(ListInventory::class)
        ->mountTableAction('adjustStock', $variant)
        ->assertMountedActionModalSee('Expiry month')
        ->fillForm([
            'quantity' => 5,
            'purchase_order_number' => 'PO-EXPIRY-REQUIRED',
            'lot_number' => 'REQUIRED-001',
            'purchased_at' => today()->toDateString(),
        ])
        ->callMountedAction()
        ->assertHasActionErrors(['expiry_month' => 'required']);

    expect(fn () => app(ReceiveContactLensStock::class)->handle(
        variant: $variant,
        quantity: 5,
        lotNumber: 'REQUIRED-001',
        expiryMonth: null,
        receiver: auth()->user(),
        purchaseOrderNumber: 'PO-EXPIRY-REQUIRED',
    ))->toThrow(ValidationException::class);

    $this->assertDatabaseCount(InventoryLot::class, 0);
    expect($variant->fresh()->stock_quantity)->toBe(0);
})->with([
    'enabled accessory' => ['accessory', true],
    'contact lenses always require expiry' => ['contact_lens', false],
]);

test('non-expiring accessory receipts can replenish the same lot without storing a supplied expiry', function (): void {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->accessory()->state(['requires_expiry' => false]))
        ->create(['stock_quantity' => 0]);

    foreach ([null, '2020-01'] as $expiryMonth) {
        app(ReceiveContactLensStock::class)->handle(
            variant: $variant,
            quantity: 3,
            lotNumber: 'CASE-001',
            expiryMonth: $expiryMonth,
            receiver: auth()->user(),
            purchaseOrderNumber: 'PO-CASE',
        );
    }

    $this->assertDatabaseCount(InventoryLot::class, 1);
    $this->assertDatabaseHas(InventoryLot::class, [
        'lot_number' => 'CASE-001',
        'expires_on' => null,
        'quantity_on_hand' => 6,
        'received_quantity' => 6,
    ]);
    expect($variant->fresh()->stock_quantity)->toBe(6);
});

test('changing the expiry option never rewrites an existing lot expiry', function (bool $requiresExpiry): void {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->accessory()->state(['requires_expiry' => $requiresExpiry]))
        ->create(['stock_quantity' => 3]);
    $existingExpiry = $requiresExpiry ? null : today()->addYear()->endOfMonth()->toDateString();
    $lot = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'EXISTING-001',
        'expires_on' => $existingExpiry,
        'quantity_on_hand' => 3,
        'received_quantity' => 3,
    ]);

    expect(fn () => app(ReceiveContactLensStock::class)->handle(
        variant: $variant,
        quantity: 2,
        lotNumber: 'EXISTING-001',
        expiryMonth: today()->addYear()->format('Y-m'),
        receiver: auth()->user(),
        purchaseOrderNumber: 'PO-CONFLICT',
    ))->toThrow(ValidationException::class);

    expect($lot->fresh()->expires_on?->toDateString())->toBe($existingExpiry)
        ->and($lot->fresh()->quantity_on_hand)->toBe(3)
        ->and($variant->fresh()->stock_quantity)->toBe(3);
    $this->assertDatabaseCount(InventoryMovement::class, 0);
})->with([true, false]);

test('accessory orders use dated stock before non-expiring stock and restore both on cancellation', function (): void {
    $this->seed(NotificationStatusSeeder::class);
    $variant = ProductVariant::factory()
        ->for(Product::factory()->accessory()->state(['requires_expiry' => false]))
        ->create(['stock_quantity' => 8]);
    $undated = InventoryLot::factory()->for($variant, 'variant')->create([
        'expires_on' => null,
        'quantity_on_hand' => 4,
        'received_quantity' => 4,
    ]);
    $dated = InventoryLot::factory()->for($variant, 'variant')->create([
        'expires_on' => today()->addYear()->toDateString(),
        'quantity_on_hand' => 2,
        'received_quantity' => 2,
    ]);
    $expired = InventoryLot::factory()->for($variant, 'variant')->create([
        'expires_on' => today()->subDay()->toDateString(),
        'quantity_on_hand' => 2,
        'received_quantity' => 2,
    ]);
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);
    $jobOrder->items()->create([
        'description' => 'Accessory',
        'quantity' => 3,
        'unit_price' => 100,
        'amount' => 300,
        'product_variant_id' => $variant->id,
        'item_kind' => CommercialItemKind::Accessory,
    ]);

    app(CommitJobOrderInventory::class)->handle($jobOrder);

    expect($dated->fresh()->quantity_on_hand)->toBe(0)
        ->and($undated->fresh()->quantity_on_hand)->toBe(3)
        ->and($expired->fresh()->quantity_on_hand)->toBe(2)
        ->and($variant->fresh()->usableStockQuantity())->toBe(3);

    app(UpdateJobOrderStatus::class)->handle($jobOrder, 'cancelled');

    expect($dated->fresh()->quantity_on_hand)->toBe(2)
        ->and($undated->fresh()->quantity_on_hand)->toBe(4)
        ->and($variant->fresh()->stock_quantity)->toBe(8);
});
