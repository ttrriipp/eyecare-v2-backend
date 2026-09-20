<?php

use App\Actions\Inventory\RecordInventoryMovement;
use App\Filament\Resources\Inventory\InventoryResource;
use App\Filament\Resources\Inventory\Pages\ListInventory;
use App\Filament\Resources\Inventory\Tables\InventoryTable;
use App\Filament\Resources\Inventory\Widgets\InventoryStatsWidget;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Support\Htmlable;
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

test('inventory table uses the received-at label and compact responsive defaults', function () {
    $table = InventoryTable::configure(Table::make(Mockery::mock(HasTable::class)));

    expect($table->getColumn('latest_purchase_date')->getLabel())->toBe('Received At')
        ->and($table->getColumn('sku')->isToggledHiddenByDefault())->toBeTrue()
        ->and($table->isStackedOnMobile())->toBeTrue();
});

test('clicking an inventory row opens its complete detail modal', function (): void {
    $product = Product::factory()->create([
        'name' => "C'est Joli 2860",
        'product_type' => 'frame',
        'usage' => null,
    ]);
    $variant = ProductVariant::factory()->for($product)->create([
        'name' => 'C4 Gold / Black',
        'sku' => 'FRM-2860-C4',
        'stock_quantity' => 2,
        'low_stock_threshold' => 1,
        'target_stock_level' => 4,
    ]);
    $restockType = InventoryMovementType::query()->firstOrCreate(['name' => 'restock']);

    InventoryMovement::factory()->for($variant, 'variant')->create([
        'inventory_movement_type_id' => $restockType->id,
        'quantity_change' => 2,
        'purchased_at' => '2026-09-01',
    ]);

    $this->actingAs($this->staff);

    $component = Livewire::test(ListInventory::class)
        ->assertTableActionExists('view', record: $variant);

    expect($component->instance()->getTable()->getRecordAction($variant))->toBe('view');

    $component
        ->mountTableAction('view', $variant)
        ->assertMountedActionModalSee([
            'Inventory details',
            "C'est Joli 2860",
            'C4 Gold / Black',
            'FRM-2860-C4',
            'Sep 1, 2026',
            '2',
            'Early Quantity',
            'Threshold',
            'Target',
        ])
        ->assertMountedActionModalDontSee('EarlyQty')
        ->assertMountedActionModalDontSee('Usage')
        ->assertMountedActionModalDontSee('Not set')
        ->assertMountedActionModalDontSee('Earliest Expiry');
});

test('inventory details show usage for usage-tracked products', function (): void {
    $product = Product::factory()->contactLens()->create();
    $variant = ProductVariant::factory()->for($product)->create();

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->mountTableAction('view', $variant)
        ->assertMountedActionModalSee(['Usage', '1 Month']);
});

test('staff can access inventory', function () {
    $this->actingAs($this->staff);

    $this->get(InventoryResource::getUrl('index'))->assertSuccessful();
});

test('inventory defaults to most recently restocked variants first', function (): void {
    $product = Product::factory()->create();
    $earlierRestock = ProductVariant::factory()->for($product)->create(['stock_quantity' => 30]);
    $laterRestock = ProductVariant::factory()->for($product)->create(['stock_quantity' => 1]);
    $neverRestocked = ProductVariant::factory()->for($product)->create(['stock_quantity' => 0]);

    $restockType = InventoryMovementType::query()->firstOrCreate(['name' => 'restock']);

    InventoryMovement::factory()->for($earlierRestock, 'variant')->create([
        'inventory_movement_type_id' => $restockType->id,
        'quantity_change' => 5,
        'created_at' => Carbon::parse('2026-08-10 09:00:00'),
    ]);

    InventoryMovement::factory()->for($laterRestock, 'variant')->create([
        'inventory_movement_type_id' => $restockType->id,
        'quantity_change' => 5,
        'created_at' => Carbon::parse('2026-08-20 09:00:00'),
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->assertCanSeeTableRecords([$laterRestock, $earlierRestock, $neverRestocked], inOrder: true);
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

test('navigation badge is hidden when nothing is low stock', function () {
    ProductVariant::factory()->create([
        'stock_quantity' => 40,
        'low_stock_threshold' => 5,
        'is_active' => true,
    ]);

    expect(InventoryResource::getNavigationBadge())->toBeNull();
});

// --- Tabs ---

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

test('low stock tab shows active variants at or below threshold', function () {
    $product = Product::factory()->create();

    $low = ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 2,
        'low_stock_threshold' => 5,
    ]);
    $empty = ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 0,
        'low_stock_threshold' => 5,
    ]);
    $healthy = ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 8,
        'low_stock_threshold' => 5,
    ]);
    $notTracked = ProductVariant::factory()->for($product)->create([
        'stock_quantity' => 0,
        'low_stock_threshold' => 0,
    ]);
    $inactive = ProductVariant::factory()->for($product)->create([
        'is_active' => false,
        'stock_quantity' => 1,
        'low_stock_threshold' => 5,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->set('activeTab', 'low_stock')
        ->assertCanSeeTableRecords([$low, $empty])
        ->assertCanNotSeeTableRecords([$healthy, $notTracked, $inactive]);
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

test('accessory inventory shows expiry details while frames do not', function () {
    Carbon::setTestNow('2026-08-28 14:00:00');
    $accessory = Product::factory()->accessory()->create();
    $accessoryVariant = ProductVariant::factory()->for($accessory)->create(['stock_quantity' => 3]);
    InventoryLot::factory()->for($accessoryVariant, 'variant')->create([
        'lot_number' => 'DROP-001',
        'expires_on' => '2026-09-30',
        'quantity_on_hand' => 3,
    ]);

    $frame = Product::factory()->create(['product_type' => 'frame']);
    $frameVariant = ProductVariant::factory()->for($frame)->create(['stock_quantity' => 3]);

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->assertCanSeeTableRecords([$accessoryVariant])
        ->assertTableColumnStateSet('usable_stock', 3, record: $accessoryVariant)
        ->assertTableColumnStateSet('earliest_expiry', '2026-09-30', record: $accessoryVariant)
        ->assertTableColumnStateSet('expiry_warning_date', '2026-06-30', record: $accessoryVariant)
        ->assertTableColumnStateSet('expiry_status', 'Expiring Soon', record: $accessoryVariant)
        ->assertActionVisible(TestAction::make('viewBatches')->table($accessoryVariant))
        ->assertActionVisible(TestAction::make('viewBatches')->table($frameVariant))
        ->set('activeTab', 'expiring_soon')
        ->assertCanSeeTableRecords([$accessoryVariant])
        ->assertCanNotSeeTableRecords([$frameVariant])
        ->mountTableAction('viewBatches', $accessoryVariant)
        ->assertMountedActionModalSee(['Accessory batches', 'DROP-001', '2026-09-30']);

    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ListInventory::class)
        ->mountTableAction('adjustStock', $frameVariant)
        ->assertMountedActionModalSee('Batch number (optional)')
        ->assertMountedActionModalDontSee('Lot number')
        ->assertMountedActionModalDontSee('Expiry month');
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
        ->assertTableColumnStateSet('expiry_warning_date', '2026-06-30', record: $variant)
        ->assertTableColumnStateSet('expiry_status', 'Expiring Soon', record: $variant)
        ->assertActionVisible(TestAction::make('viewBatches')->table($variant))
        ->mountTableAction('viewBatches', $variant)
        ->assertMountedActionModalSee([
            'Contact-lens batches',
            'ACME-001',
            '2026-09-30',
            'ACME-002',
            'Expired',
        ])
        ->assertMountedActionModalDontSee('Stock is issued from the earliest usable expiry first.');
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

test('inventory stats include accessory expiry queues but exclude frames', function () {
    Carbon::setTestNow('2026-08-28 14:00:00');
    $accessory = Product::factory()->accessory()->create();
    $accessoryVariant = ProductVariant::factory()->for($accessory)->create(['stock_quantity' => 2]);
    InventoryLot::factory()->for($accessoryVariant, 'variant')->create([
        'expires_on' => '2026-09-30',
        'quantity_on_hand' => 2,
    ]);

    $frame = Product::factory()->create(['product_type' => 'frame']);
    $frameVariant = ProductVariant::factory()->for($frame)->create(['stock_quantity' => 2]);

    $this->actingAs($this->staff);

    $widget = Livewire::test(InventoryStatsWidget::class)->instance();
    $stats = collect((fn (): array => $this->getStats())->call($widget))->keyBy(
        fn (Stat $stat): string => (string) $stat->getLabel(),
    );

    expect($stats->get('Expiring Soon')?->getValue())->toBe('1')
        ->and($stats->get('Expired')?->getValue())->toBe('0')
        ->and($frameVariant->isExpiryTracked())->toBeFalse();
});

test('inventory stats stay focused on actionable stock queues', function () {
    $this->actingAs($this->staff);

    $widget = Livewire::test(InventoryStatsWidget::class)->instance();
    $stats = collect((fn (): array => $this->getStats())->call($widget))->keyBy(
        fn (Stat $stat): string => (string) $stat->getLabel(),
    );

    expect($stats)->toHaveCount(4)
        ->and($stats)->toHaveKeys(['Low Stock', 'Out of Stock', 'Expiring Soon', 'Expired'])
        ->and($stats)->not->toHaveKeys(['Active Variants', 'Stock Value']);
});

test('inventory KPI stats omit helper descriptions', function () {
    $this->actingAs($this->staff);

    $widget = Livewire::test(InventoryStatsWidget::class)->instance();
    $stats = (fn (): array => $this->getStats())->call($widget);

    $descriptions = array_map(
        fn (Stat $stat): string|Htmlable|null => $stat->getDescription(),
        $stats,
    );

    expect($descriptions)->toBe([null, null, null, null]);
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

test('receiving stock records and displays the purchase date', function (): void {
    Carbon::setTestNow('2026-09-16 10:00:00');
    $variant = ProductVariant::factory()->create([
        'stock_quantity' => 4,
    ]);

    $this->actingAs($this->staff);

    $component = Livewire::test(ListInventory::class)
        ->assertTableColumnExists('latest_purchase_date')
        ->assertTableColumnExists('early_purchase_quantity')
        ->mountTableAction('adjustStock', $variant)
        ->assertMountedActionModalSee('Date Received');

    $component
        ->unmountAction()
        ->callAction(TestAction::make('adjustStock')->table($variant), [
            'quantity' => 10,
            'purchased_at' => '2026-09-01',
        ])
        ->assertHasNoActionErrors();

    Livewire::test(ListInventory::class)
        ->callAction(TestAction::make('adjustStock')->table($variant), [
            'quantity' => 10,
            'purchased_at' => '2026-08-01',
        ])
        ->assertHasNoActionErrors();

    expect(InventoryMovement::query()->orderBy('purchased_at')->firstOrFail()->purchased_at->toDateString())
        ->toBe('2026-08-01');

    Livewire::test(ListInventory::class)
        ->assertTableColumnFormattedStateSet('latest_purchase_date', 'Aug 1, 2026', record: $variant)
        ->assertTableColumnStateSet('early_purchase_quantity', 10, record: $variant);
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
            'purchased_at' => '2026-08-20',
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

    expect(InventoryMovement::query()->sole()->purchased_at->toDateString())->toBe('2026-08-20');

    Livewire::test(ListInventory::class)
        ->mountTableAction('viewBatches', $variant)
        ->assertMountedActionModalSee('Aug 20, 2026');
});

test('receiving accessories captures their lot and expiry month', function () {
    $product = Product::factory()->accessory()->create();
    $variant = ProductVariant::factory()->for($product)->create(['stock_quantity' => 0]);

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->callAction(TestAction::make('adjustStock')->table($variant), [
            'quantity' => 5,
            'lot_number' => 'DROP-001',
            'expiry_month' => '2027-06',
            'purchased_at' => '2026-08-20',
        ])
        ->assertHasNoActionErrors();

    $lot = InventoryLot::query()->sole();

    expect($variant->fresh()->stock_quantity)->toBe(5)
        ->and($lot->expires_on->toDateString())->toBe('2027-06-30')
        ->and($lot->quantity_on_hand)->toBe(5);
});

test('contact lens receiving explains the expiry month requirement before submission', function () {
    Carbon::setTestNow('2026-08-28 14:00:00');
    $product = Product::factory()->contactLens()->create();
    $variant = ProductVariant::factory()->for($product)->create();

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->mountTableAction('adjustStock', $variant)
        ->assertMountedActionModalSee('Use the expiry month printed on the box. It must be the current month or later; expired contact lens stock cannot be received.')
        ->assertMountedActionModalSeeHtml('min="2026-08"');
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

test('writing off accessories requires and decrements a selected lot', function () {
    $product = Product::factory()->accessory()->create();
    $variant = ProductVariant::factory()->for($product)->create(['stock_quantity' => 5]);
    $lot = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'DROP-001',
        'expires_on' => '2027-06-30',
        'quantity_on_hand' => 5,
        'received_quantity' => 5,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->callAction(TestAction::make('writeOffDamaged')->table($variant), [
            'quantity' => 2,
            'inventory_lot_id' => $lot->id,
            'notes' => 'Bottle damaged',
        ])
        ->assertHasNoActionErrors();

    expect($variant->fresh()->stock_quantity)->toBe(3)
        ->and($lot->fresh()->quantity_on_hand)->toBe(3);
});

test('frames receive a non-expiring batch without expiry fields', function () {
    $product = Product::factory()->create(['product_type' => 'frame']);
    $variant = ProductVariant::factory()->for($product)->create(['stock_quantity' => 1]);

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->callAction(TestAction::make('adjustStock')->table($variant), [
            'quantity' => 4,
            'purchased_at' => '2026-08-20',
        ])
        ->assertHasNoActionErrors();

    $batch = InventoryLot::query()->sole();

    expect($variant->fresh()->stock_quantity)->toBe(5)
        ->and($batch->lot_number)->toBe(sprintf(
            'FRM-%d-260820-1',
            $variant->id,
        ))
        ->and($batch->expires_on)->toBeNull()
        ->and($batch->quantity_on_hand)->toBe(4)
        ->and(InventoryMovement::query()->sole()->inventory_lot_id)->toBe($batch->id);

    Livewire::test(ListInventory::class)
        ->mountTableAction('viewBatches', $variant)
        ->assertMountedActionModalSee([
            'Frame batches',
            sprintf('FRM-%d-260820-1', $variant->id),
            'Available',
        ])
        ->assertMountedActionModalDontSee('Expires');
});

test('inventory batch view renders the frame empty state', function (): void {
    $html = view('filament.inventory.inventory-lots', [
        'lots' => collect(),
        'showExpiry' => false,
        'unbatchedQuantity' => 0,
    ])->render();

    expect($html)->toContain('No batches have been received for this variant.');
});

test('writing off frames decrements the selected batch', function () {
    $product = Product::factory()->create(['product_type' => 'frame']);
    $variant = ProductVariant::factory()->for($product)->create(['stock_quantity' => 5]);
    $batch = InventoryLot::factory()->for($variant, 'variant')->create([
        'lot_number' => 'FRAME-001',
        'expires_on' => null,
        'received_quantity' => 5,
        'quantity_on_hand' => 5,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(ListInventory::class)
        ->mountTableAction('writeOffDamaged', $variant)
        ->assertMountedActionModalSee('Batch')
        ->assertMountedActionModalDontSee('Expiry');

    Livewire::test(ListInventory::class)
        ->callAction(TestAction::make('writeOffDamaged')->table($variant), [
            'quantity' => 2,
            'inventory_lot_id' => $batch->id,
            'notes' => 'Frame scratched during display',
        ])
        ->assertHasNoActionErrors();

    expect($variant->fresh()->stock_quantity)->toBe(3)
        ->and($batch->fresh()->quantity_on_hand)->toBe(3)
        ->and(InventoryMovement::query()->sole()->inventory_lot_id)->toBe($batch->id);
});

test('administrators can override an automatically generated frame batch number', function () {
    $product = Product::factory()->create(['product_type' => 'frame']);
    $variant = ProductVariant::factory()->for($product)->create(['stock_quantity' => 0]);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ListInventory::class)
        ->callAction(TestAction::make('adjustStock')->table($variant), [
            'quantity' => 2,
            'batch_number' => 'SPECIAL-FRAME-001',
            'purchased_at' => '2026-08-20',
        ])
        ->assertHasNoActionErrors();

    expect(InventoryLot::query()->sole()->lot_number)->toBe('SPECIAL-FRAME-001');
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

// --- Low stock helper ---

test('isLowStock identifies variants at or below threshold', function () {
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

    $lowStock = ProductVariant::all()
        ->filter(fn (ProductVariant $v): bool => $v->isLowStock());

    expect($lowStock)->toHaveCount(1);
});
