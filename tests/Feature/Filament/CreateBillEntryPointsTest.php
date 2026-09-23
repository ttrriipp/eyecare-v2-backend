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
use Database\Seeders\DatabaseSeeder;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
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

    $billComponent = Livewire::test(CreateBillingRecord::class, ['encounter' => $encounter->id])
        ->assertFormSet([
            'patient_id' => $patient->id,
            'prescription_id' => $prescription->id,
            'include_prescription_eyewear' => true,
        ])
        ->assertFormFieldDisabled('patient_id');

    expect($billComponent->get('data.service_items'))->toHaveCount(1);
});

test('seeded consultations expose billing only when their consultation bill is missing', function () {
    $this->seed(DatabaseSeeder::class);

    $staff = User::query()->where('email', 'staff@eyecare.test')->firstOrFail();
    $billedEncounter = Encounter::query()->where('encounter_number', 'CON-2026-000001')->firstOrFail();
    $unbilledEncounter = Encounter::query()->where('encounter_number', 'CON-2026-000006')->firstOrFail();

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $billedEncounter->getRouteKey()])
        ->assertActionHidden('createBill');

    Livewire::test(EditEncounter::class, ['record' => $unbilledEncounter->getRouteKey()])
        ->assertActionVisible('createBill')
        ->assertSee('BR-2026-000004')
        ->assertActionHasUrl('createBill', BillingRecordResource::getUrl('create', [
            'encounter' => $unbilledEncounter->id,
        ]));
});

test('create bill items use the service-style source selector layout', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff);

    $component = Livewire::test(CreateBillingRecord::class);
    $itemSource = collect($component->instance()->form->getFlatFields(withHidden: true))
        ->first(fn (Field $field, string $key): bool => str_ends_with($key, '.item_kind'));

    expect($itemSource)
        ->toBeInstanceOf(Radio::class)
        ->and($itemSource->getLabel())
        ->toBe('Item source')
        ->and($itemSource->isInline())
        ->toBeTrue()
        ->and($itemSource->getOptions())
        ->toBe([
            'catalog' => 'Catalog item',
            'custom' => 'Custom item',
        ]);
});
