<?php

use App\Enums\JobOrderStatus;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\OpticalOrders\Pages\EditOpticalOrder;
use App\Filament\Resources\OpticalOrders\Pages\ListOpticalOrders;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can list optical orders', function () {
    $staff = User::factory()->staff()->create();
    $jobOrders = JobOrder::factory()->count(3)->create();

    $this->actingAs($staff);

    Livewire::test(ListOpticalOrders::class)
        ->assertCanSeeTableRecords($jobOrders);
});

test('staff can view an optical order', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create(['first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Santos']);
    $jobOrder = JobOrder::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($staff);

    Livewire::test(EditOpticalOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Maria Santos');
});

test('staff can see line items on an optical order', function () {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create();
    JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'description' => 'Single Vision Lens',
        'quantity' => 2,
        'unit_price' => 1750,
        'amount' => 3500,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditOpticalOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Single Vision Lens')
        ->assertSee('3,500.00');
});

test('marking an optical order ready records the required supplier invoice number', function () {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::InProgress,
        'uses_external_supplier' => true,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditOpticalOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->callAction('markReady', [
            'supplier_invoice_number' => 'SUP-INV-3002',
        ])
        ->assertHasNoActionErrors();

    expect($jobOrder->fresh()->status)->toBe(JobOrderStatus::ReadyForDispensing)
        ->and($jobOrder->fresh()->supplier_invoice_number)->toBe('SUP-INV-3002');
});

test('optical order resource is registered', function () {
    expect(OpticalOrderResource::getModel())->toBe(JobOrder::class);
});
