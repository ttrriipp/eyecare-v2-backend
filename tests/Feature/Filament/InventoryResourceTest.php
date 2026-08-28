<?php

use App\Actions\Inventory\RecordInventoryMovement;
use App\Filament\Resources\Inventory\InventoryResource;
use App\Filament\Resources\Inventory\Pages\ListInventory;
use App\Filament\Resources\Inventory\Widgets\InventoryStatsWidget;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

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

test('needs reorder uses usable contact-lens quantity instead of expired physical stock', function (): void {
    Carbon::setTestNow('2026-08-28 14:00:00');
    $product = Product::factory()->contactLens()->create();

    $needsReorder = ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 10,
        'low_stock_threshold' => 3,
        'target_stock_level' => 12,
    ]);
    InventoryLot::factory()->for($needsReorder, 'variant')->create([
        'expires_on' => '2026-08-27',
        'received_quantity' => 8,
        'quantity_on_hand' => 8,
    ]);
    InventoryLot::factory()->for($needsReorder, 'variant')->create([
        'expires_on' => '2026-12-31',
        'received_quantity' => 2,
        'quantity_on_hand' => 2,
    ]);

    $healthy = ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 10,
        'low_stock_threshold' => 3,
    ]);
    InventoryLot::factory()->for($healthy, 'variant')->create([
        'expires_on' => '2027-12-31',
        'received_quantity' => 10,
        'quantity_on_hand' => 10,
    ]);

    expect($needsReorder->isLowStock())->toBeTrue()
        ->and($needsReorder->suggestedReorderQuantity())->toBe(10);

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->set('activeTab', 'needs_reorder')
        ->assertCanSeeTableRecords([$needsReorder])
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

test('expiry tabs show expiring and fully expired contact-lens variants', function () {
    Carbon::setTestNow('2026-08-28 14:00:00');
    $product = Product::factory()->contactLens()->create();

    $expiring = ProductVariant::factory()->for($product)->create(['stock_quantity' => 2]);
    InventoryLot::factory()->for($expiring, 'variant')->create([
        'expires_on' => '2026-09-30',
        'quantity_on_hand' => 2,
    ]);

    $expired = ProductVariant::factory()->for($product)->create(['stock_quantity' => 2]);
    InventoryLot::factory()->for($expired, 'variant')->create([
        'expires_on' => '2026-08-27',
        'quantity_on_hand' => 2,
    ]);

    $good = ProductVariant::factory()->for($product)->create(['stock_quantity' => 2]);
    InventoryLot::factory()->for($good, 'variant')->create([
        'expires_on' => '2027-08-31',
        'quantity_on_hand' => 2,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->set('activeTab', 'expiring_soon')
        ->assertCanSeeTableRecords([$expiring])
        ->assertCanNotSeeTableRecords([$expired, $good]);

    Livewire::test(ListInventory::class)
        ->set('activeTab', 'expired')
        ->assertCanSeeTableRecords([$expired])
        ->assertCanNotSeeTableRecords([$expiring, $good]);
});

test('contact lens inventory shows usable quantity, earliest expiry, and status', function () {
    Carbon::setTestNow('2026-08-28 14:00:00');
    $product = Product::factory()->contactLens()->create();
    $variant = ProductVariant::factory()->for($product)->create(['stock_quantity' => 5]);
    InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'ACME-001',
        'expires_on' => '2026-09-30',
        'quantity_on_hand' => 3,
    ]);
    InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'ACME-002',
        'expires_on' => '2026-08-27',
        'quantity_on_hand' => 2,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->assertTableColumnStateSet('usable_stock', 3, record: $variant)
        ->assertTableColumnStateSet('earliest_expiry', '2026-09-30', record: $variant)
        ->assertTableColumnStateSet('expiry_status', 'Expiring Soon', record: $variant)
        ->assertActionVisible(TestAction::make('viewBatches')->table($variant))
        ->mountTableAction('viewBatches', $variant)
        ->assertMountedActionModalSee([
            'ACME-001',
            '2026-09-30',
            'ACME-002',
            'Expired',
        ]);
});

test('inventory stats include contact-lens expiry queues', function () {
    Carbon::setTestNow('2026-08-28 14:00:00');
    $product = Product::factory()->contactLens()->create();

    $expiring = ProductVariant::factory()->for($product)->create(['stock_quantity' => 1]);
    InventoryLot::factory()->for($expiring, 'variant')->create([
        'expires_on' => '2026-09-30',
        'quantity_on_hand' => 1,
    ]);

    $expired = ProductVariant::factory()->for($product)->create(['stock_quantity' => 1]);
    InventoryLot::factory()->for($expired, 'variant')->create([
        'expires_on' => '2026-08-27',
        'quantity_on_hand' => 1,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(InventoryStatsWidget::class)
        ->assertSuccessful()
        ->assertSee('Expiring Soon')
        ->assertSee('Expired');
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

test('writing off contact lenses requires and decrements a selected lot', function () {
    $product = Product::factory()->contactLens()->create();
    $variant = ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 5,
    ]);
    $lot = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'ACME-001',
        'quantity_on_hand' => 5,
        'received_quantity' => 5,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->callAction(TestAction::make('writeOffDamaged')->table($variant), [
            'quantity' => 2,
            'inventory_lot_id' => $lot->id,
            'notes' => 'Box crushed',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($variant->fresh()->stock_quantity)->toBe(3)
        ->and($lot->fresh()->quantity_on_hand)->toBe(3);

    $this->assertDatabaseHas('inventory_movements', [
        'product_variant_id' => $variant->id,
        'inventory_lot_id' => $lot->id,
        'quantity_change' => -2,
        'notes' => 'Box crushed',
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
