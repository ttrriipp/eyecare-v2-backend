<?php

use App\Enums\EncounterStatus;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Patients\RelationManagers\BillingRelationManager;
use App\Filament\Resources\Patients\RelationManagers\EncountersRelationManager;
use App\Filament\Resources\Patients\RelationManagers\OpticalOrdersRelationManager;
use App\Filament\Resources\Patients\RelationManagers\PrescriptionsRelationManager;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('encounters relation manager lists the patient\'s encounters', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'encounter_number' => 'CON-2026-000456',
    ]);
    $otherEncounter = Encounter::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EncountersRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => EditPatient::class,
    ])
        ->assertSee('Consultations')
        ->assertSee('Consultation #')
        ->assertDontSee('Encounter #')
        ->assertCanSeeTableRecords([$encounter])
        ->assertCanNotSeeTableRecords([$otherEncounter]);
});

test('encounters relation manager renders voided consultations', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $voidedEncounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'status' => EncounterStatus::Voided,
    ]);

    $this->actingAs($staff);

    Livewire::test(EncountersRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => EditPatient::class,
    ])
        ->assertCanSeeTableRecords([$voidedEncounter])
        ->assertTableColumnFormattedStateSet('status', 'Voided', record: $voidedEncounter);
});

test('optical orders relation manager lists the patient\'s job orders', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $jobOrder = JobOrder::factory()->create(['patient_id' => $patient->id]);
    $otherJobOrder = JobOrder::factory()->create();

    $this->actingAs($staff);

    Livewire::test(OpticalOrdersRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => EditPatient::class,
    ])
        ->assertCanSeeTableRecords([$jobOrder])
        ->assertCanNotSeeTableRecords([$otherJobOrder]);
});

test('prescriptions relation manager uses consultation terminology', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->create(['patient_id' => $patient->id]);
    $prescription = Prescription::factory()->create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(PrescriptionsRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => EditPatient::class,
    ])
        ->assertSee('Consultation')
        ->assertDontSee('Encounter')
        ->assertCanSeeTableRecords([$prescription]);
});

test('billing relation manager lists the patient\'s billing records', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $billingRecord = BillingRecord::factory()->create(['patient_id' => $patient->id]);
    $otherBillingRecord = BillingRecord::factory()->create();

    $this->actingAs($staff);

    Livewire::test(BillingRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => EditPatient::class,
    ])
        ->assertCanSeeTableRecords([$billingRecord])
        ->assertCanNotSeeTableRecords([$otherBillingRecord]);
});

test('patient resource registers the new linked record tabs', function () {
    expect(PatientResource::getRelations())
        ->toContain(EncountersRelationManager::class)
        ->toContain(OpticalOrdersRelationManager::class)
        ->toContain(BillingRelationManager::class);
});
