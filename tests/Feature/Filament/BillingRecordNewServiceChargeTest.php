<?php

use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Filament\Resources\BillingRecords\Pages\ListBillingRecords;
use App\Models\BillingRecord;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can create a direct service charge from the billing list', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ListBillingRecords::class)
        ->assertActionVisible('newServiceCharge')
        ->mountAction('newServiceCharge')
        ->assertMountedActionModalSee([
            'Add Service Charge',
            'Patient',
            'Service source',
            'Catalog service',
            'Custom service',
            'Add Service Line',
            'Total',
            'Add to Billing',
        ])
        ->unmountAction()
        ->callAction('newServiceCharge', [
            'patient_id' => $patient->id,
            'items' => [[
                'service_source' => 'custom',
                'description' => 'Contact Lens Fitting',
                'quantity' => 1,
                'unit_price' => 800,
            ]],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified()
        ->assertRedirect();

    $billing = BillingRecord::query()->where('patient_id', $patient->id)->firstOrFail();

    expect($billing->encounter_id)->toBeNull()
        ->and($billing->job_order_id)->toBeNull()
        ->and((float) $billing->subtotal_amount)->toBe(800.0);

    expect(BillingRecordResource::getUrl('edit', ['record' => $billing]))
        ->toContain("/billing-records/{$billing->id}/edit");
});

test('catalog service charges use the selected service description and price', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $service = Service::factory()->create([
        'name' => 'Comprehensive Eye Exam',
        'price' => 800,
    ]);

    $this->actingAs($staff);

    Livewire::test(ListBillingRecords::class)
        ->callAction('newServiceCharge', [
            'patient_id' => $patient->id,
            'items' => [[
                'service_source' => 'catalog',
                'service_id' => $service->id,
                'description' => 'Tampered description',
                'quantity' => 1,
                'unit_price' => 1,
            ]],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified()
        ->assertRedirect();

    $billingItem = BillingRecord::query()
        ->where('patient_id', $patient->id)
        ->firstOrFail()
        ->items
        ->firstOrFail();

    expect($billingItem->service_id)->toBe($service->id)
        ->and($billingItem->description)->toBe('Comprehensive Eye Exam')
        ->and((float) $billingItem->unit_price)->toBe(800.0)
        ->and((float) $billingItem->amount)->toBe(800.0);
});

test('new service charge action requires at least one item', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ListBillingRecords::class)
        ->callAction('newServiceCharge', [
            'patient_id' => $patient->id,
            'items' => [],
        ])
        ->assertHasActionErrors(['items' => 'min']);

    expect(BillingRecord::query()->where('patient_id', $patient->id)->exists())->toBeFalse();
});
