<?php

use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Filament\Resources\BillingRecords\Pages\CreateBillingRecord;
use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Prescriptions\Pages\ViewPrescription;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('patient, prescription, and encounter actions open the unified bill page', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->completed()->create([
        'patient_id' => $patient->id,
    ]);
    $prescription = Prescription::factory()->create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditPatient::class, ['record' => $patient->getRouteKey()])
        ->assertActionVisible('createBill')
        ->assertActionHasUrl('createBill', BillingRecordResource::getUrl('create', [
            'patient' => $patient->id,
        ]));

    Livewire::test(ViewPrescription::class, ['record' => $prescription->getRouteKey()])
        ->assertActionVisible('createBill')
        ->assertActionHasUrl('createBill', BillingRecordResource::getUrl('create', [
            'patient' => $patient->id,
            'prescription' => $prescription->id,
        ]));

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionVisible('createBill')
        ->assertActionHasUrl('createBill', BillingRecordResource::getUrl('create', [
            'encounter' => $encounter->id,
        ]));

    Livewire::test(CreateBillingRecord::class, ['encounter' => $encounter->id])
        ->assertFormSet([
            'patient_id' => $patient->id,
            'prescription_id' => $prescription->id,
            'include_prescription_eyewear' => true,
        ])
        ->assertFormFieldDisabled('patient_id');
});
