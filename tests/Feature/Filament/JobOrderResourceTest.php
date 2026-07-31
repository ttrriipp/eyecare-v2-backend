<?php

use App\Enums\JobOrderStatus;
use App\Filament\Resources\JobOrders\JobOrderResource;
use App\Filament\Resources\JobOrders\Pages\EditJobOrder;
use App\Filament\Resources\JobOrders\Pages\ListJobOrders;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can list job orders', function () {
    $staff = User::factory()->staff()->create();
    $jobOrders = JobOrder::factory()->count(3)->create();

    $this->actingAs($staff);

    Livewire::test(ListJobOrders::class)
        ->assertCanSeeTableRecords($jobOrders);
});

test('staff can view a job order', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);
    $jobOrder = JobOrder::factory()->create(['patient_id' => $patient->id]);

    $this->actingAs($staff);

    Livewire::test(EditJobOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Maria Santos');
});

test('staff can see line items on a job order', function () {
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

    Livewire::test(EditJobOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Single Vision Lens')
        ->assertSee('3,500.00');
});

test('staff can record a supplier invoice number on a job order', function () {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditJobOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->fillForm(['supplier_invoice_number' => 'SUP-INV-3001'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($jobOrder->fresh()->supplier_invoice_number)->toBe('SUP-INV-3001');
});

test('marking a job order ready records the required supplier invoice number', function () {
    $staff = User::factory()->staff()->create();
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::InProgress]);

    $this->actingAs($staff);

    Livewire::test(EditJobOrder::class, ['record' => $jobOrder->getRouteKey()])
        ->callAction('markReady', [
            'supplier_invoice_number' => 'SUP-INV-3002',
        ])
        ->assertHasNoActionErrors();

    expect($jobOrder->fresh()->status)->toBe(JobOrderStatus::ReadyForDispensing)
        ->and($jobOrder->fresh()->supplier_invoice_number)->toBe('SUP-INV-3002');
});

test('job order resource is registered', function () {
    expect(JobOrderResource::getModel())->toBe(JobOrder::class);
});
