<?php

use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Encounter;
use App\Models\Prescription;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('receptionist creates a quotation from an eligible encounter', function () {
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionVisible('createQuotation')
        ->callAction('createQuotation', [
            'valid_until' => now()->addWeek()->toDateString(),
            'discount_amount' => 250,
            'notes' => 'Patient-visible estimate note.',
            'internal_notes' => 'Clinic-only preparation note.',
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

    expect($quotation->latestRevision->total)->toBe('12250.00')
        ->and($quotation->notes)->toBe('Patient-visible estimate note.')
        ->and($quotation->internal_notes)->toBe('Clinic-only preparation note.');

    expect(QuotationResource::getUrl('edit', ['record' => $quotation]))
        ->toContain("/quotations/{$quotation->id}/edit");
});

test('quotation action is available for a completed encounter with a current prescription', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->completed()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionVisible('createQuotation');
});

test('quotation action is hidden until a prescription exists and after a quotation exists', function () {
    $staff = User::factory()->staff()->create();
    $withoutPrescription = Encounter::factory()->inProgress()->create();
    $withQuotation = Encounter::factory()->inProgress()->create();
    $prescription = Prescription::factory()->linkedToEncounter($withQuotation)->create();
    Quotation::factory()->create([
        'patient_id' => $withQuotation->patient_id,
        'encounter_id' => $withQuotation->id,
        'prescription_id' => $prescription->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $withoutPrescription->getRouteKey()])
        ->assertActionHidden('createQuotation');

    Livewire::test(EditEncounter::class, ['record' => $withQuotation->getRouteKey()])
        ->assertActionHidden('createQuotation')
        ->assertActionVisible('viewQuotation');
});

test('quotation action validates that at least one item is supplied', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->callAction('createQuotation', [
            'discount_amount' => 0,
            'items' => [],
        ])
        ->assertHasActionErrors(['items' => 'min']);

    expect(Quotation::query()->where('encounter_id', $encounter->id)->exists())->toBeFalse();
});
