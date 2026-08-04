<?php

use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Filament\Resources\BillingRecords\Pages\ListBillingRecords;
use App\Models\BillingRecord;
use App\Models\Patient;
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
        ->callAction('newServiceCharge', [
            'patient_id' => $patient->id,
            'items' => [[
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
