<?php

use App\Enums\EncounterStatus;
use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\RelationManagers\PrescriptionsRelationManager;
use App\Filament\Resources\Prescriptions\Pages\AmendPrescription;
use App\Filament\Resources\Prescriptions\Pages\CreatePrescription;
use App\Filament\Resources\Prescriptions\Pages\ListPrescriptions;
use App\Filament\Resources\Prescriptions\Pages\ViewPrescription;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('prescription lists show retained operational columns', function () {
    $optometrist = User::factory()->optometrist()->create(['first_name' => 'Dr.', 'middle_name' => null, 'last_name' => 'Padilla']);
    $patient = Patient::factory()->create([
        'first_name' => 'Maria',
        'middle_name' => null,
        'last_name' => 'Santos',
    ]);
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'optometrist_id' => $optometrist->id,
        'encounter_number' => 'ENC-000123',
    ]);
    $prescription = Prescription::factory()->create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'created_by' => $optometrist->id,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(ListPrescriptions::class)
        ->assertTableColumnStateSet('patient.full_name', 'Maria Santos', record: $prescription)
        ->assertTableColumnStateSet('encounter.encounter_number', 'ENC-000123', record: $prescription)
        ->assertTableColumnStateSet('version_status', 'Original', record: $prescription)
        ->assertTableColumnStateSet('author.first_name', 'Dr. Padilla', record: $prescription)
        ->assertTableColumnDoesNotExist('expires_at')
        ->assertTableColumnDoesNotExist('pd')
        ->assertTableColumnDoesNotExist('createdBy.first_name');

    Livewire::test(PrescriptionsRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => EditPatient::class,
    ])
        ->assertTableColumnStateSet('encounter.encounter_number', 'ENC-000123', record: $prescription)
        ->assertTableColumnStateSet('version_status', 'Original', record: $prescription)
        ->assertTableColumnStateSet('author.first_name', 'Dr. Padilla', record: $prescription)
        ->assertTableColumnDoesNotExist('od_sphere')
        ->assertTableColumnDoesNotExist('os_sphere')
        ->assertTableColumnDoesNotExist('expires_at');
});

test('prescription form omits unsupported prism and base fields', function () {
    $optometrist = User::factory()->admin()->optometrist()->create();
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $optometrist->id,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(CreatePrescription::class, ['encounter' => $encounter->id])
        ->assertFormFieldExists('main_od_sphere')
        ->assertFormFieldExists('main_os_sphere')
        ->assertFormFieldDoesNotExist('show_prism_base')
        ->assertFormFieldDoesNotExist('od_prism')
        ->assertFormFieldDoesNotExist('od_base')
        ->assertFormFieldDoesNotExist('os_prism')
        ->assertFormFieldDoesNotExist('os_base');
});

test('an optometrist can create a prescription from the encounter wizard', function () {
    $optometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $optometrist->id,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertFormFieldExists('prescription.main_od_sphere')
        ->assertFormFieldExists('prescription.main_os_sphere')
        ->assertFormFieldExists('plan');

    Livewire::test(CreatePrescription::class, ['encounter' => $encounter->id])
        ->assertSuccessful()
        ->assertFormSet([
            'patient_id' => $encounter->patient_id,
            'appointment_id' => null,
        ])
        ->assertFormFieldDisabled('patient_id')
        ->assertFormFieldDisabled('appointment_id');
});

test('prescription form is hidden outside an active encounter wizard', function () {
    $optometrist = User::factory()->optometrist()->create();
    $receptionist = User::factory()->staff()->create();
    $planned = Encounter::factory()->create(['status' => EncounterStatus::Planned]);
    $inProgress = Encounter::factory()->inProgress()->create();
    $completed = Encounter::factory()->completed()->create();

    $this->actingAs($optometrist);

    Livewire::test(EditEncounter::class, ['record' => $planned->getRouteKey()])
        ->assertFormFieldDoesNotExist('prescription.main_od_sphere');
    Livewire::test(EditEncounter::class, ['record' => $completed->getRouteKey()])
        ->assertFormFieldDoesNotExist('prescription.main_od_sphere');

    $this->actingAs($receptionist);

    Livewire::test(EditEncounter::class, ['record' => $inProgress->getRouteKey()])
        ->assertFormFieldExists('prescription.main_od_sphere')
        ->assertFormFieldDisabled('prescription.main_od_sphere');
});

test('direct prescription creation rejects unauthorized or inactive encounter access', function () {
    $optometrist = User::factory()->optometrist()->create();
    $receptionist = User::factory()->staff()->create();
    $planned = Encounter::factory()->create(['status' => EncounterStatus::Planned]);
    $inProgress = Encounter::factory()->inProgress()->create();

    $this->actingAs($optometrist)
        ->get(PrescriptionResource::getUrl('create', ['encounter' => $planned->id]))
        ->assertForbidden();

    $this->actingAs($receptionist)
        ->get(PrescriptionResource::getUrl('create', ['encounter' => $inProgress->id]))
        ->assertForbidden();
});

test('prescription creation derives ownership from the encounter', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $otherPatient = Patient::factory()->create();
    $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);
    $encounter = Encounter::factory()->inProgress()->create([
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'optometrist_id' => $optometrist->id,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(CreatePrescription::class, ['encounter' => $encounter->id])
        ->fillForm([
            'patient_id' => $otherPatient->id,
            'appointment_id' => null,
            'main_od_sphere' => '-2.50',
            'main_os_sphere' => '-3.00',
            'remarks' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $prescription = Prescription::query()->sole();

    expect($prescription->patient_id)->toBe($patient->id)
        ->and($prescription->encounter_id)->toBe($encounter->id)
        ->and($prescription->appointment_id)->toBe($appointment->id)
        ->and($prescription->created_by)->toBe($optometrist->id);
});

test('a finalized prescription is read only and offers amendment to optometrists', function () {
    $optometrist = User::factory()->optometrist()->create();
    $prescription = Prescription::factory()->create([
        'main_od_sphere' => '-2.00',
        'remarks' => 'Stable distance vision.',
    ]);

    $this->actingAs($optometrist);

    Livewire::test(ViewPrescription::class, ['record' => $prescription->getRouteKey()])
        ->assertSuccessful()
        ->assertSee($prescription->patient->full_name)
        ->assertSee('-2.00')
        ->assertSee('Stable distance vision.')
        ->assertActionVisible('amendPrescription')
        ->assertActionDoesNotExist('edit')
        ->assertActionDoesNotExist('archive')
        ->assertActionDoesNotExist('restore');
});

test('a receptionist can view but cannot amend a finalized prescription', function () {
    $receptionist = User::factory()->staff()->create();
    $prescription = Prescription::factory()
        ->linkedToEncounter(Encounter::factory()->completed()->create())
        ->create();

    $this->actingAs($receptionist);

    Livewire::test(ViewPrescription::class, ['record' => $prescription->getRouteKey()])
        ->assertSuccessful()
        ->assertActionHidden('amendPrescription');

    $this->get(PrescriptionResource::getUrl('amend', ['previous' => $prescription->id]))
        ->assertForbidden();
});

test('an optometrist can amend a prescription without changing the original', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->completed()->create([
        'patient_id' => $patient->id,
        'optometrist_id' => $optometrist->id,
    ]);
    $original = Prescription::factory()->linkedToEncounter($encounter)->create([
        'main_od_sphere' => '-2.00',
    ]);

    $this->actingAs($optometrist);

    Livewire::test(AmendPrescription::class, ['previous' => $original->id])
        ->assertSuccessful()
        ->assertFormSet([
            'patient_id' => $patient->id,
            'main_od_sphere' => '-2.00',
        ])
        ->assertFormFieldDisabled('patient_id')
        ->fillForm([
            'main_od_sphere' => '-2.50',
            'amendment_reason' => 'Corrected transcription.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $original->refresh();
    $amendment = Prescription::query()
        ->where('previous_prescription_id', $original->id)
        ->sole();

    expect($original->main_od_sphere)->toBe('-2.00')
        ->and($amendment->main_od_sphere)->toBe('-2.5')
        ->and($amendment->patient_id)->toBe($original->patient_id)
        ->and($amendment->encounter_id)->toBe($original->encounter_id)
        ->and($amendment->appointment_id)->toBe($original->appointment_id)
        ->and($amendment->created_by)->toBe($optometrist->id)
        ->and($amendment->amendment_reason)->toBe('Corrected transcription.')
        ->and(AuditLog::query()->where('subject_id', $amendment->id)->exists())->toBeTrue();

    Livewire::test(ViewPrescription::class, ['record' => $amendment->getRouteKey()])
        ->assertFormFieldExists('amendment_reason')
        ->assertFormFieldDisabled('amendment_reason')
        ->assertActionVisible('print_prescription')
        ->assertFormSet([
            'amendment_reason' => 'Corrected transcription.',
        ]);

    Livewire::test(ViewPrescription::class, ['record' => $original->getRouteKey()])
        ->assertActionHidden('print_prescription')
        ->assertActionVisible('viewCurrentPrescription');
});

test('amendment reason is required', function () {
    $optometrist = User::factory()->optometrist()->create();
    $original = Prescription::factory()
        ->linkedToEncounter(Encounter::factory()->completed()->create())
        ->create();

    $this->actingAs($optometrist);

    Livewire::test(AmendPrescription::class, ['previous' => $original->id])
        ->fillForm(['amendment_reason' => null])
        ->call('create')
        ->assertHasFormErrors(['amendment_reason' => 'required']);
});

test('prescription finalization forms cannot create another record', function () {
    $optometrist = User::factory()->optometrist()->create();
    $newEncounter = Encounter::factory()->inProgress()->create();
    $previousPrescription = Prescription::factory()
        ->linkedToEncounter(Encounter::factory()->completed()->create())
        ->create();

    $this->actingAs($optometrist);

    $createPage = Livewire::test(CreatePrescription::class, [
        'encounter' => $newEncounter->id,
    ]);
    $amendPage = Livewire::test(AmendPrescription::class, [
        'previous' => $previousPrescription->id,
    ]);

    expect($createPage->instance()->canCreateAnother())->toBeFalse()
        ->and($amendPage->instance()->canCreateAnother())->toBeFalse();
});
