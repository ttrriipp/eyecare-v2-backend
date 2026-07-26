<?php

use App\Filament\Resources\InventoryMovements\InventoryMovementResource;
use App\Filament\Resources\InventoryMovements\Pages\ListInventoryMovements;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('movement history links canonical sources', function () {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create();
    $movement = InventoryMovement::factory()->create(['job_order_id' => $jobOrder->id]);

    $this->actingAs($staff);

    Livewire::test(ListInventoryMovements::class)
        ->assertCanSeeTableRecords(collect([$movement]));
});

test('movement history shows job order number', function () {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create(['job_order_number' => 'JO-TEST-001']);
    $movement = InventoryMovement::factory()->create(['job_order_id' => $jobOrder->id]);

    $this->actingAs($staff);

    Livewire::test(ListInventoryMovements::class)
        ->assertTableColumnFormattedStateSet('jobOrder.job_order_number', 'JO-TEST-001', record: $movement);
});

test('inventory resource is registered', function () {
    expect(InventoryMovementResource::getModel())->toBe(InventoryMovement::class);
});
