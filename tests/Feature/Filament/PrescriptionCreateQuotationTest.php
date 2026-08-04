<?php

use App\Filament\Resources\Prescriptions\Pages\ViewPrescription;
use App\Models\Encounter;
use App\Models\Prescription;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('create quotation action is hidden without an encounter', function () {
    $staff = User::factory()->staff()->create();
    $prescription = Prescription::factory()->create(['encounter_id' => null]);

    $this->actingAs($staff);

    Livewire::test(ViewPrescription::class, ['record' => $prescription->getRouteKey()])
        ->assertActionHidden('createQuotation');
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

test('create quotation action is hidden when the encounter already has a quotation', function () {
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
        ->assertActionHidden('createQuotation');
});
