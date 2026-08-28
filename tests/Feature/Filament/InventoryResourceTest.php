<?php

use App\Actions\Inventory\RecordInventoryMovement;
use App\Filament\Resources\Inventory\InventoryResource;
use App\Filament\Resources\Inventory\Pages\ListInventory;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
});

// --- Resource configuration ---

test('inventory is backed by product variants', function () {
    expect(InventoryResource::getModel())->toBe(ProductVariant::class);
});

test('variants cannot be created from the inventory page', function () {
    // Variants belong to a product; Products remains the only editor.
    expect(InventoryResource::canCreate())->toBeFalse();
});

test('staff can access inventory', function () {
    $this->actingAs($this->staff);

    $this->get(InventoryResource::getUrl('index'))->assertSuccessful();
});

// --- Navigation badge ---

test('navigation badge counts only active variants at or below threshold', function () {
    $product = Product::factory()->create();

    ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 1,
        'low_stock_threshold' => 5,
        'is_active' => true,
    ]);
    ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 50,
        'low_stock_threshold' => 5,
        'is_active' => true,
    ]);
    ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 0,
        'low_stock_threshold' => 5,
        'is_active' => false,
    ]);

    expect(InventoryResource::getNavigationBadge())->toBe('1');
});

test('navigation badge is hidden when nothing needs reordering', function () {
    ProductVariant::factory()->create([
        'stock_quantity' => 40,
        'low_stock_threshold' => 5,
        'is_active' => true,
    ]);

    expect(InventoryResource::getNavigationBadge())->toBeNull();
});

// --- Tabs ---

test('needs reorder tab shows only variants at or below threshold', function () {
    $product = Product::factory()->create();

    $low = ProductVariant::factory()->for($product)->create([
        'name' => 'Low Stock Variant',
        'stock_quantity' => 2,
        'low_stock_threshold' => 5,
        'is_active' => true,
    ]);
    $healthy = ProductVariant::factory()->for($product)->create([
        'name' => 'Healthy Stock Variant',
        'stock_quantity' => 80,
        'low_stock_threshold' => 5,
        'is_active' => true,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->set('activeTab', 'needs_reorder')
        ->assertCanSeeTableRecords([$low])
        ->assertCanNotSeeTableRecords([$healthy]);
});

test('out of stock tab shows only depleted variants', function () {
    $product = Product::factory()->create();

    $empty = ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 0,
        'low_stock_threshold' => 5,
    ]);
    $stocked = ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 3,
        'low_stock_threshold' => 5,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->set('activeTab', 'out_of_stock')
        ->assertCanSeeTableRecords([$empty])
        ->assertCanNotSeeTableRecords([$stocked]);
});

test('the default tab shows every variant', function () {
    $product = Product::factory()->create();

    $low = ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 1,
        'low_stock_threshold' => 5,
    ]);
    $healthy = ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 90,
        'low_stock_threshold' => 5,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->assertCanSeeTableRecords([$low, $healthy]);
});

// --- Stock actions ---

test('receiving stock raises the quantity and writes a ledger entry', function () {
    $variant = ProductVariant::factory()->create([
        'stock_quantity' => 4,
        'low_stock_threshold' => 5,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->callAction(TestAction::make('adjustStock')->table($variant), [
            'quantity' => 10,
        ]);

    expect($variant->fresh()->stock_quantity)->toBe(14);

    $this->assertDatabaseHas('inventory_movements', [
        'product_variant_id' => $variant->id,
        'quantity_change' => 10,
        'previous_stock' => 4,
        'new_stock' => 14,
    ]);
});

test('receiving contact lenses captures their lot and expiry month', function () {
    $product = Product::factory()->contactLens()->create();
    $variant = ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 0,
    ]);
    expect($variant->product->product_type)->toBe('contact_lens');

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->callAction(TestAction::make('adjustStock')->table($variant), [
            'quantity' => 10,
            'lot_number' => 'ACME-001',
            'expiry_month' => '2027-06',
            'source_reference' => 'PO-42',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(InventoryMovement::query()->count())->toBe(1);

    $lot = InventoryLot::query()->sole();

    expect($variant->fresh()->stock_quantity)->toBe(10)
        ->and($lot->lot_number)->toBe('ACME-001')
        ->and($lot->expires_on->toDateString())->toBe('2027-06-30')
        ->and($lot->quantity_on_hand)->toBe(10);
});

test('aggregate movements use the locked stock value for ledger boundaries', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 4]);
    $staleVariant = $variant->fresh();

    DB::table('product_variants')
        ->where('id', $variant->id)
        ->update(['stock_quantity' => 9]);

    app(RecordInventoryMovement::class)->handle(
        variant: $staleVariant,
        quantityChange: 1,
        type: 'restock',
        actingUser: $this->staff,
    );

    $movement = InventoryMovement::query()->sole();

    expect($movement->previous_stock)->toBe(9)
        ->and($movement->new_stock)->toBe(10)
        ->and($variant->fresh()->stock_quantity)->toBe(10);
});

test('writing off damaged stock lowers the quantity and writes a ledger entry', function () {
    $variant = ProductVariant::factory()->create([
        'stock_quantity' => 8,
        'low_stock_threshold' => 5,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->callAction(TestAction::make('writeOffDamaged')->table($variant), [
            'quantity' => 3,
            'notes' => 'Lens cracked in storage',
        ]);

    expect($variant->fresh()->stock_quantity)->toBe(5);

    $this->assertDatabaseHas('inventory_movements', [
        'product_variant_id' => $variant->id,
        'quantity_change' => -3,
        'notes' => 'Lens cracked in storage',
    ]);
});

// --- Shared action definitions ---

test('the products variants tab still receives stock through the shared action', function () {
    // Both surfaces use App\Filament\Support\StockActions, so this proves the
    // extraction left the Products path working, not just the new page.
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 6,
        'low_stock_threshold' => 5,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->callAction(TestAction::make('adjustStock')->table($variant), [
            'quantity' => 4,
        ])
        ->assertHasNoActionErrors();

    expect($variant->fresh()->stock_quantity)->toBe(10);

    $this->assertDatabaseHas('inventory_movements', [
        'product_variant_id' => $variant->id,
        'quantity_change' => 4,
    ]);
});

// --- Suggested reorder quantity ---

test('suggested reorder quantity is the gap up to the target level', function () {
    $variant = ProductVariant::factory()->create([
        'stock_quantity' => 2,
        'low_stock_threshold' => 5,
        'target_stock_level' => 20,
    ]);

    expect($variant->suggestedReorderQuantity())->toBe(18);
});

test('suggested reorder quantity is null when no target is configured', function () {
    $variant = ProductVariant::factory()->create([
        'stock_quantity' => 2,
        'low_stock_threshold' => 5,
        'target_stock_level' => null,
    ]);

    expect($variant->suggestedReorderQuantity())->toBeNull();
});

// --- Low stock helper mirrors the query scope ---

test('isLowStock matches the needsReorder scope', function () {
    $product = Product::factory()->create();

    ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 2,
        'low_stock_threshold' => 5,
    ]);
    ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 90,
        'low_stock_threshold' => 5,
    ]);
    // A threshold of zero means "not tracked" and must never count as low.
    ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 0,
        'low_stock_threshold' => 0,
    ]);

    $scopeMatched = ProductVariant::query()->needsReorder()->pluck('id')->sort()->values();
    $helperMatched = ProductVariant::all()
        ->filter(fn (ProductVariant $v): bool => $v->isLowStock())
        ->pluck('id')->sort()->values();

    expect($helperMatched->all())->toBe($scopeMatched->all())
        ->and($scopeMatched)->toHaveCount(1);
});
