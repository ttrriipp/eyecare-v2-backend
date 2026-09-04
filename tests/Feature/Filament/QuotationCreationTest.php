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

test('create quotation action is available for a completed encounter with a current prescription and links to the create page', function () {
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);
    $encounter = Encounter::factory()->completed()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $this->actingAs($staff);

    $component = Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionVisible('createQuotation')
        ->assertActionHasLabel('createQuotation', 'Create Quotation');

    expect($component->instance()->getAction('createQuotation')->getUrl())
        ->toBe(QuotationResource::getUrl('create', ['encounter' => $encounter->id]));
});

test('create quotation action is available for a completed encounter without a prescription', function () {
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);
    $encounter = Encounter::factory()->completed()->create();

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionVisible('createQuotation');
});

test('create quotation action is hidden for an in-progress encounter even with a current prescription', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionHidden('createQuotation');
});

test('create quotation action remains hidden after a quotation exists', function () {
    $staff = User::factory()->staff()->create();
    $withQuotation = Encounter::factory()->completed()->create();
    $prescription = Prescription::factory()->linkedToEncounter($withQuotation)->create();
    Quotation::factory()->create([
        'patient_id' => $withQuotation->patient_id,
        'encounter_id' => $withQuotation->id,
        'prescription_id' => $prescription->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $withQuotation->getRouteKey()])
        ->assertActionHidden('createQuotation')
        ->assertActionDoesNotExist('viewQuotation')
        ->assertActionDoesNotExist('viewOpticalOrder');
});
