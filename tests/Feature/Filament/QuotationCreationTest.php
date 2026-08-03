<?php

use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Models\Encounter;
use App\Models\Prescription;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('receptionist creates an optical order from an eligible encounter', function () {
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionVisible('createOpticalOrder')
        ->assertActionHasLabel('createOpticalOrder', 'Create Optical Order')
        ->callAction('createOpticalOrder', [
            'valid_until' => now()->addWeek()->toDateString(),
            'discount_amount' => 250,
            'notes' => 'Patient-visible estimate note.',
            'items' => [[
                'item_type' => 'custom',
                'description' => 'Complete frame and single vision lens',
                'quantity' => 1,
                'unit_price' => 12500,
            ]],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified()
        ->assertRedirect();

    $quotation = Quotation::query()->where('encounter_id', $encounter->id)->firstOrFail();

    expect($quotation->total)->toBe('12250.00')
        ->and($quotation->notes)->toBe('Patient-visible estimate note.')
        ->and($quotation->internal_notes)->toBeNull();

    expect(OpticalOrderResource::getUrl('edit', ['record' => $quotation]))
        ->toContain("/optical-orders/{$quotation->id}/edit");
});

test('optical order action is available for a completed encounter with a current prescription', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->completed()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionVisible('createOpticalOrder');
});

test('optical order action is hidden until a prescription exists and changes to view after an order exists', function () {
    $staff = User::factory()->staff()->create();
    $withoutPrescription = Encounter::factory()->inProgress()->create();
    $withQuotation = Encounter::factory()->inProgress()->create();
    $prescription = Prescription::factory()->linkedToEncounter($withQuotation)->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $withQuotation->patient_id,
        'encounter_id' => $withQuotation->id,
        'prescription_id' => $prescription->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $withoutPrescription->getRouteKey()])
        ->assertActionHidden('createOpticalOrder');

    $component = Livewire::test(EditEncounter::class, ['record' => $withQuotation->getRouteKey()])
        ->assertActionHidden('createOpticalOrder')
        ->assertActionDoesNotExist('viewQuotation')
        ->assertActionVisible('viewOpticalOrder')
        ->assertActionHasLabel('viewOpticalOrder', 'View Optical Order');

    expect($component->instance()->getAction('viewOpticalOrder')->getUrl())
        ->toBe(OpticalOrderResource::getUrl('view', ['record' => $quotation]));
});

test('optical order action validates that at least one item is supplied', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->callAction('createOpticalOrder', [
            'discount_amount' => 0,
            'items' => [],
        ])
        ->assertHasActionErrors(['items' => 'min']);

    expect(Quotation::query()->where('encounter_id', $encounter->id)->exists())->toBeFalse();
});
