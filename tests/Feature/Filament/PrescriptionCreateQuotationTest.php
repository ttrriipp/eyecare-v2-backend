<?php

use App\Filament\Resources\Prescriptions\Pages\ViewPrescription;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Encounter;
use App\Models\Prescription;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('create quotation action is visible without an encounter, using the prescription directly', function () {
    $staff = User::factory()->staff()->create();
    $prescription = Prescription::factory()->create(['encounter_id' => null]);

    $this->actingAs($staff);

    Livewire::test(ViewPrescription::class, ['record' => $prescription->getRouteKey()])
        ->assertActionVisible('createQuotation');
});

test('create quotation action is hidden on a superseded prescription', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->completed()->create();
    $original = Prescription::factory()->linkedToEncounter($encounter)->create();
    Prescription::factory()->linkedToEncounter($encounter)->create([
        'previous_prescription_id' => $original->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewPrescription::class, ['record' => $original->getRouteKey()])
        ->assertActionHidden('createQuotation');
});

test('create quotation action stays visible when the prescription\'s originating encounter already has a quotation', function () {
    // A prescription's own encounter having one quotation must not block using
    // that same still-current prescription for an unrelated future quotation.
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->completed()->create();
    $prescription = Prescription::factory()->linkedToEncounter($encounter)->create();
    Quotation::factory()->create([
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'prescription_id' => $prescription->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ViewPrescription::class, ['record' => $prescription->getRouteKey()])
        ->assertActionVisible('createQuotation');
});

test('the create quotation action links to the prescription, not the encounter', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->completed()->create();
    $prescription = Prescription::factory()->linkedToEncounter($encounter)->create();

    $this->actingAs($staff);

    Livewire::test(ViewPrescription::class, ['record' => $prescription->getRouteKey()])
        ->assertActionHasUrl('createQuotation', QuotationResource::getUrl('create', [
            'prescription' => $prescription->id,
        ]));
});
