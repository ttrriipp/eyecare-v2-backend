<?php

use App\Filament\Resources\InventoryMovements\Pages\ListInventoryMovements;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\InventoryMovementTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(InventoryMovementTypeSeeder::class);
});

test('staff and admin can view inventory movements list', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $variant = ProductVariant::factory()->create();

    InventoryMovement::factory()->create([
        'product_variant_id' => $variant->id,
        'quantity_change' => 10,
    ]);

    $this->actingAs($user);

    Livewire::test(ListInventoryMovements::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(InventoryMovement::all());
})->with(['staff', 'admin']);

test('inventory movement table shows product, variant, type, quantity change, and date', function () {
    $staff = User::factory()->staff()->create();
    $variant = ProductVariant::factory()->create(['name' => 'Gold']);

    $movement = InventoryMovement::factory()->create([
        'product_variant_id' => $variant->id,
        'quantity_change' => -1,
    ]);

    $this->actingAs($staff);

    Livewire::test(ListInventoryMovements::class)
        ->assertCanSeeTableRecords([$movement]);
});

test('inventory movement table can filter by movement type', function () {
    $staff = User::factory()->staff()->create();

    $restock = InventoryMovement::factory()->create([
        'inventory_movement_type_id' => InventoryMovementType::query()->where('name', 'restock')->value('id'),
    ]);
    $sale = InventoryMovement::factory()->create([
        'inventory_movement_type_id' => InventoryMovementType::query()->where('name', 'sale')->value('id'),
    ]);

    $this->actingAs($staff);

    $restockTypeId = InventoryMovementType::query()->where('name', 'restock')->value('id');

    Livewire::test(ListInventoryMovements::class)
        ->filterTable('movementType', $restockTypeId)
        ->assertCanSeeTableRecords([$restock])
        ->assertCanNotSeeTableRecords([$sale]);
});
