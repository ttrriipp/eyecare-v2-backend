<?php

use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CatalogStockReceiptReferencesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('catalog batches include stable purchase order and delivery receipt references', function (): void {
    Storage::fake('public');
    User::factory()->staff()->create();

    $this->seed(CatalogSeeder::class);

    $receipts = InventoryMovement::query()
        ->with('variant.product')
        ->where('notes', 'Opening stock seeded from catalog.')
        ->whereNotNull('inventory_lot_id')
        ->orderBy('id')
        ->get();

    expect($receipts)->not->toBeEmpty();
    expect($receipts->pluck('variant.product.product_type')->unique()->sort()->values()->all())
        ->toBe(['accessory', 'contact_lens', 'frame']);

    foreach ($receipts as $receipt) {
        expect($receipt->purchase_order_number)->toBeString()->toMatch('/\APO-\d{6,}\z/');
        expect($receipt->delivery_receipt_number)->toBeString()->toMatch('/\ADR-\d{6,}\z/');
    }

    expect($receipts->pluck('purchase_order_number')->unique())->toHaveCount($receipts->count());
    expect($receipts->pluck('delivery_receipt_number')->unique())->toHaveCount($receipts->count());

    $references = $receipts->map->only(['id', 'inventory_lot_id', 'purchase_order_number', 'delivery_receipt_number'])->all();
    $this->seed(CatalogSeeder::class);

    expect(InventoryMovement::query()->whereIn('id', $receipts->modelKeys())
        ->orderBy('id')
        ->get(['id', 'inventory_lot_id', 'purchase_order_number', 'delivery_receipt_number'])->toArray())
        ->toBe($references);
    $this->assertDatabaseCount(InventoryMovement::class, $receipts->count());
});

test('receipt reference backfills preserve existing stock quantities and receipt history', function (): void {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 7]);
    $lot = InventoryLot::factory()->for($variant, 'variant')->create([
        'received_quantity' => 10,
        'quantity_on_hand' => 7,
        'source_reference' => 'Catalog opening stock',
    ]);
    $restockType = InventoryMovementType::query()->firstOrCreate(['name' => 'restock']);
    $receipt = InventoryMovement::factory()->for($variant, 'variant')->for($lot, 'lot')->create([
        'inventory_movement_type_id' => $restockType->id,
        'quantity_change' => 10,
        'previous_stock' => 0,
        'new_stock' => 10,
        'notes' => 'Opening stock seeded from catalog.',
        'purchase_order_number' => null,
        'delivery_receipt_number' => null,
    ]);
    $variantAttributes = $variant->fresh()->getAttributes();
    $lotAttributes = $lot->fresh()->getAttributes();

    $this->seed(CatalogStockReceiptReferencesSeeder::class);

    $updatedReceipt = $receipt->fresh();
    expect($updatedReceipt->purchase_order_number)->toBeString()->toMatch('/\APO-\d{6,}\z/');
    expect($updatedReceipt->delivery_receipt_number)->toBeString()->toMatch('/\ADR-\d{6,}\z/');
    expect($variant->fresh()->getAttributes())->toBe($variantAttributes);
    expect($lot->fresh()->getAttributes())->toBe($lotAttributes);
    expect($updatedReceipt->only(['quantity_change', 'previous_stock', 'new_stock']))
        ->toBe(['quantity_change' => 10, 'previous_stock' => 0, 'new_stock' => 10]);
    $this->assertDatabaseCount(InventoryMovement::class, 1);

    $this->seed(CatalogStockReceiptReferencesSeeder::class);

    expect($receipt->fresh()->only(['purchase_order_number', 'delivery_receipt_number']))
        ->toBe($updatedReceipt->only(['purchase_order_number', 'delivery_receipt_number']));
});

test('receipt reference backfills preserve references already supplied', function (?string $purchaseOrder, ?string $deliveryReceipt): void {
    $variant = ProductVariant::factory()->create();
    $lot = InventoryLot::factory()->for($variant, 'variant')->create(['source_reference' => 'Catalog opening stock']);
    $restockType = InventoryMovementType::query()->firstOrCreate(['name' => 'restock']);
    $receipt = InventoryMovement::factory()->for($variant, 'variant')->for($lot, 'lot')->create([
        'inventory_movement_type_id' => $restockType->id,
        'quantity_change' => 5,
        'notes' => 'Opening stock seeded from catalog.',
        'purchase_order_number' => $purchaseOrder,
        'delivery_receipt_number' => $deliveryReceipt,
    ]);

    $this->seed(CatalogStockReceiptReferencesSeeder::class);

    $receipt->refresh();

    if ($purchaseOrder !== null) {
        expect($receipt->purchase_order_number)->toBe($purchaseOrder);
    } else {
        expect($receipt->purchase_order_number)->toBeString()->toMatch('/\APO-\d{6,}\z/');
    }

    if ($deliveryReceipt !== null) {
        expect($receipt->delivery_receipt_number)->toBe($deliveryReceipt);
    } else {
        expect($receipt->delivery_receipt_number)->toBeString()->toMatch('/\ADR-\d{6,}\z/');
    }
})->with([
    'both references supplied' => ['PO-EXISTING', 'DR-EXISTING'],
    'purchase order supplied' => ['PO-EXISTING', null],
    'delivery receipt supplied' => [null, 'DR-EXISTING'],
    'manually supplied references containing SEED' => ['PO-SEED-MANUAL', 'DR-SEED-MANUAL'],
]);

test('receipt reference backfills remove SEED from previously generated references', function (): void {
    $variant = ProductVariant::factory()->create();
    $lot = InventoryLot::factory()->for($variant, 'variant')->create(['source_reference' => 'Catalog opening stock']);
    $restockType = InventoryMovementType::query()->firstOrCreate(['name' => 'restock']);
    $receipt = InventoryMovement::factory()->for($variant, 'variant')->for($lot, 'lot')->create([
        'inventory_movement_type_id' => $restockType->id,
        'quantity_change' => 5,
        'notes' => 'Opening stock seeded from catalog.',
    ]);
    $purchaseOrder = sprintf('PO-SEED-%06d', $receipt->id);
    $deliveryReceipt = sprintf('DR-SEED-%06d', $receipt->id);
    $receipt->update([
        'purchase_order_number' => $purchaseOrder,
        'delivery_receipt_number' => $deliveryReceipt,
    ]);

    $this->seed(CatalogStockReceiptReferencesSeeder::class);

    expect($receipt->fresh()->purchase_order_number)->toBe(str_replace('-SEED-', '-', $purchaseOrder));
    expect($receipt->fresh()->delivery_receipt_number)->toBe(str_replace('-SEED-', '-', $deliveryReceipt));

    $updatedReferences = $receipt->fresh()->only(['purchase_order_number', 'delivery_receipt_number']);
    $this->seed(CatalogStockReceiptReferencesSeeder::class);

    expect($receipt->fresh()->only(['purchase_order_number', 'delivery_receipt_number']))->toBe($updatedReferences);
});

test('receipt reference backfills leave other inventory movements unchanged', function (string $notes, string $sourceReference, string $type, int $quantity): void {
    $variant = ProductVariant::factory()->create();
    $lot = InventoryLot::factory()->for($variant, 'variant')->create(['source_reference' => $sourceReference]);
    $movementType = InventoryMovementType::query()->firstOrCreate(['name' => $type]);
    $movement = InventoryMovement::factory()->for($variant, 'variant')->for($lot, 'lot')->create([
        'inventory_movement_type_id' => $movementType->id,
        'quantity_change' => $quantity,
        'notes' => $notes,
        'purchase_order_number' => null,
        'delivery_receipt_number' => null,
    ]);
    $originalAttributes = $movement->fresh()->getAttributes();

    $this->seed(CatalogStockReceiptReferencesSeeder::class);

    expect($movement->fresh()->getAttributes())->toBe($originalAttributes);
})->with([
    'manual receipt' => ['Received by staff', 'Catalog opening stock', 'restock', 5],
    'non-catalog batch' => ['Opening stock seeded from catalog.', 'Supplier delivery', 'restock', 5],
    'non-restock movement' => ['Opening stock seeded from catalog.', 'Catalog opening stock', 'manual_adjustment', 5],
    'zero receipt quantity' => ['Opening stock seeded from catalog.', 'Catalog opening stock', 'restock', 0],
    'negative receipt quantity' => ['Opening stock seeded from catalog.', 'Catalog opening stock', 'restock', -1],
]);
