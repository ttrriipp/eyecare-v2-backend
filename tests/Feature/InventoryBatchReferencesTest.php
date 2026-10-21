<?php

use App\Filament\Resources\Inventory\Pages\ListInventory;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->actingAs(User::factory()->staff()->create());
});

test('batch viewing shows purchase order and delivery receipt references for received stock', function (string $productType): void {
    $variant = ProductVariant::factory()
        ->for(Product::factory()->state(['product_type' => $productType]))
        ->create();
    $lot = InventoryLot::factory()->for($variant, 'variant')->create();
    $restockType = InventoryMovementType::query()->firstOrCreate(['name' => 'restock']);
    InventoryMovement::factory()->for($variant, 'variant')->for($lot, 'lot')->create([
        'inventory_movement_type_id' => $restockType->id,
        'quantity_change' => 5,
        'purchase_order_number' => 'PO/2026-0081',
        'delivery_receipt_number' => 'DR-ACME/26-001',
    ]);

    Livewire::test(ListInventory::class)
        ->mountTableAction('viewBatches', $variant)
        ->assertMountedActionModalSee([
            'Purchase Order',
            'PO/2026-0081',
            'Delivery Receipt',
            'DR-ACME/26-001',
        ])
        ->assertMountedActionModalSeeHtml('<th scope="col" class="px-4 py-3">Purchase Order</th>')
        ->assertMountedActionModalSeeHtml('<th scope="col" class="px-4 py-3">Delivery Receipt</th>')
        ->assertMountedActionModalDontSee('Purchase documents');
})->with(['frame', 'contact_lens', 'accessory']);

test('batch viewing keeps document references from repeated receipts and excludes other movements', function (): void {
    $variant = ProductVariant::factory()->create();
    $lot = InventoryLot::factory()->for($variant, 'variant')->create(['expires_on' => null]);
    $restockType = InventoryMovementType::query()->firstOrCreate(['name' => 'restock']);
    InventoryMovement::factory()->for($variant, 'variant')->for($lot, 'lot')->create([
        'inventory_movement_type_id' => $restockType->id,
        'quantity_change' => 5,
        'purchase_order_number' => 'PO-FIRST',
        'delivery_receipt_number' => 'DR-FIRST',
    ]);
    InventoryMovement::factory()->for($variant, 'variant')->for($lot, 'lot')->create([
        'inventory_movement_type_id' => $restockType->id,
        'quantity_change' => 3,
        'purchase_order_number' => 'PO-SECOND',
        'delivery_receipt_number' => null,
    ]);
    InventoryMovement::factory()->for($variant, 'variant')->for($lot, 'lot')->create([
        'quantity_change' => 1,
        'purchase_order_number' => 'NOT-A-RECEIPT',
        'delivery_receipt_number' => 'NOT-A-DELIVERY',
    ]);
    InventoryMovement::factory()->for($variant, 'variant')->for($lot, 'lot')->create([
        'inventory_movement_type_id' => $restockType->id,
        'quantity_change' => -1,
        'purchase_order_number' => 'NEGATIVE-RESTOCK',
    ]);
    $otherVariant = ProductVariant::factory()->create();
    $otherLot = InventoryLot::factory()->for($otherVariant, 'variant')->create();
    InventoryMovement::factory()->for($otherVariant, 'variant')->for($otherLot, 'lot')->create([
        'inventory_movement_type_id' => $restockType->id,
        'quantity_change' => 4,
        'purchase_order_number' => 'OTHER-VARIANT-PO',
    ]);

    Livewire::test(ListInventory::class)
        ->mountTableAction('viewBatches', $variant)
        ->assertMountedActionModalSee(['PO-FIRST', 'DR-FIRST', 'PO-SECOND'])
        ->assertMountedActionModalSeeHtml('rowspan="2"')
        ->assertMountedActionModalDontSee([
            'NOT-A-RECEIPT',
            'NOT-A-DELIVERY',
            'NEGATIVE-RESTOCK',
            'OTHER-VARIANT-PO',
        ]);
});

test('batch viewing handles receipts without a delivery receipt number', function (): void {
    $variant = ProductVariant::factory()->create();
    $lot = InventoryLot::factory()->for($variant, 'variant')->create(['expires_on' => null]);
    $restockType = InventoryMovementType::query()->firstOrCreate(['name' => 'restock']);
    InventoryMovement::factory()->for($variant, 'variant')->for($lot, 'lot')->create([
        'inventory_movement_type_id' => $restockType->id,
        'quantity_change' => 5,
        'purchase_order_number' => 'PO-WITHOUT-DR',
        'delivery_receipt_number' => null,
    ]);

    Livewire::test(ListInventory::class)
        ->mountTableAction('viewBatches', $variant)
        ->assertMountedActionModalSee(['PO-WITHOUT-DR', 'Purchase Order', 'Delivery Receipt'])
        ->assertMountedActionModalSeeHtml('<td class="break-all px-4 py-3">—</td>')
        ->assertMountedActionModalDontSee('Purchase documents');
});

test('batch document references render as escaped plain text', function (): void {
    $variant = ProductVariant::factory()->create();
    $lot = InventoryLot::factory()->for($variant, 'variant')->create(['expires_on' => null]);
    $restockType = InventoryMovementType::query()->firstOrCreate(['name' => 'restock']);
    $purchaseOrder = '<script>purchaseOrder()</script>';
    $deliveryReceipt = '<img src=x onerror="deliveryReceipt()">';
    InventoryMovement::factory()->for($variant, 'variant')->for($lot, 'lot')->create([
        'inventory_movement_type_id' => $restockType->id,
        'quantity_change' => 5,
        'purchase_order_number' => $purchaseOrder,
        'delivery_receipt_number' => $deliveryReceipt,
    ]);

    Livewire::test(ListInventory::class)
        ->mountTableAction('viewBatches', $variant)
        ->assertMountedActionModalSeeHtml(e($purchaseOrder))
        ->assertMountedActionModalSeeHtml(e($deliveryReceipt))
        ->assertMountedActionModalDontSeeHtml($purchaseOrder)
        ->assertMountedActionModalDontSeeHtml($deliveryReceipt);
});

test('legacy batches without receipt movements can still be viewed', function (): void {
    $variant = ProductVariant::factory()->create();
    InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'LEGACY-BATCH',
        'expires_on' => null,
    ]);

    Livewire::test(ListInventory::class)
        ->mountTableAction('viewBatches', $variant)
        ->assertMountedActionModalSee(['LEGACY-BATCH', 'Purchase Order', 'Delivery Receipt'])
        ->assertMountedActionModalDontSee('Purchase documents');
});
