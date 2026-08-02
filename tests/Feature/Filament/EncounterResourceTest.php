<?php

use App\Enums\EncounterStatus;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Filament\Resources\Encounters\Pages\ListEncounters;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('optometrist can list encounters', function () {
    $optometrist = User::factory()->optometrist()->create();
    $encounters = Encounter::factory()->count(3)->create();

    $this->actingAs($optometrist);

    Livewire::test(ListEncounters::class)
        ->assertCanSeeTableRecords($encounters);
});

test('optometrist can view encounter details', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->create([
        'patient_id' => $patient->id,
        'status' => EncounterStatus::Planned,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Encounter')
        ->assertSee('Patient')
        ->assertSee('Timeline')
        ->assertDontSee('Health Record Status');
});

test('in-progress encounter shows only the consultation wizard', function () {
    $optometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $optometrist->id,
    ]);

    $this->actingAs($optometrist);

    $component = Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertWizardStepExists(1)
        ->assertWizardStepExists(2)
        ->assertWizardStepExists(3)
        ->assertWizardStepExists(4)
        ->assertFormFieldExists('prescription.main_od_sphere')
        ->assertFormFieldDoesNotExist('plan')
        ->assertSee('Save & Continue')
        ->assertSee('Back')
        ->assertDontSee('Save changes')
        ->assertDontSee('Encounter Information')
        ->assertDontSee('Cancel Appointment');

    expect($component->html())->toMatch(
        '/<button\b(?=[^>]*fi-size-md)(?=[^>]*fi-ac-btn-action)(?=[^>]*fi-color-success)[^>]*>.*?Complete Visit.*?<\/button>/s',
    );
});

test('save and continue persists the current step without completing the encounter', function () {
    $optometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $optometrist->id,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->fillForm(['chief_complaint' => 'Blurred vision'])
        ->goToNextWizardStep()
        ->assertWizardCurrentStep(2);

    $encounter = $encounter->fresh();

    expect($encounter->status)->toBe(EncounterStatus::InProgress)
        ->and($encounter->chief_complaint)->toBe('Blurred vision');
});

test('encounter wizard step three finalizes the prescription for the encounter', function () {
    $optometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $optometrist->id,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->fillForm([
            'chief_complaint' => 'Blurred vision',
            'findings' => 'Myopic correction indicated.',
            'prescription' => [
                'main_od_sphere' => '-1.00',
                'main_os_sphere' => '-1.25',
                'remarks' => 'Wear as needed for distance.',
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $prescription = Prescription::query()->where('encounter_id', $encounter->id)->first();

    expect($prescription)->not->toBeNull()
        ->and($prescription->patient_id)->toBe($encounter->patient_id)
        ->and($prescription->main_od_sphere)->toBe('-1')
        ->and($prescription->main_os_sphere)->toBe('-1.25');
});

test('encounter wizard step four summarizes the overall consultation', function () {
    $optometrist = User::factory()->optometrist()->create();
    $patient = Patient::factory()->create([
        'first_name' => 'Maria',
        'middle_name' => null,
        'last_name' => 'Santos',
    ]);
    $encounter = Encounter::factory()->inProgress()->create([
        'patient_id' => $patient->id,
        'optometrist_id' => $optometrist->id,
        'chief_complaint' => 'Blurred vision',
        'past_ocular_history' => 'Wears glasses',
        'findings' => 'Distance acuity reduced.',
        'remarks' => 'Follow-up in six months.',
    ]);
    Prescription::factory()->linkedToEncounter($encounter)->create([
        'main_od_sphere' => '-1.00',
        'main_os_sphere' => '-1.25',
    ]);

    $this->actingAs($optometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertSee('Encounter Summary')
        ->assertSee('Maria Santos')
        ->assertSee('Blurred vision')
        ->assertSee('Wears glasses')
        ->assertSee('Distance acuity reduced.')
        ->assertSee('Follow-up in six months.')
        ->assertSee('-1.00')
        ->assertSee('-1.25');
});

test('encounter page has no cancel appointment action', function () {
    $optometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $optometrist->id,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionDoesNotExist('cancelAppointment');
});

test('in-progress encounter can complete the visit from the wizard confirmation action', function () {
    $optometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $optometrist->id,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionExists('completeVisit')
        ->assertActionHasLabel('completeVisit', 'Complete Visit')
        ->mountAction('completeVisit')
        ->assertActionMounted('completeVisit')
        ->assertMountedActionModalSee('Complete Visit')
        ->callMountedAction();

    expect($encounter->fresh()->status)->toBe(EncounterStatus::Completed);
});

test('encounter table shows status badges', function () {
    $optometrist = User::factory()->optometrist()->create();
    $waiting = Encounter::factory()->create(['status' => EncounterStatus::Planned]);
    $inProgress = Encounter::factory()->inProgress()->create();
    $completed = Encounter::factory()->completed()->create();

    $this->actingAs($optometrist);

    Livewire::test(ListEncounters::class)
        ->assertTableColumnFormattedStateSet('status', 'Planned', record: $waiting)
        ->assertTableColumnFormattedStateSet('status', 'In Progress', record: $inProgress)
        ->assertTableColumnFormattedStateSet('status', 'Completed', record: $completed);
});

test('encounter table can filter by status', function () {
    $optometrist = User::factory()->optometrist()->create();
    $waiting = Encounter::factory()->create(['status' => EncounterStatus::Planned]);
    $completed = Encounter::factory()->completed()->create();

    $this->actingAs($optometrist);

    Livewire::test(ListEncounters::class)
        ->filterTable('status', EncounterStatus::Planned->value)
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$completed]);
});

test('receptionist can see encounters but not clinical authoring', function () {
    $staff = User::factory()->staff()->create(['is_optometrist' => false]);
    $encounter = Encounter::factory()->create();

    $this->actingAs($staff);

    // Staff can list and view encounters
    Livewire::test(ListEncounters::class)
        ->assertCanSeeTableRecords([$encounter]);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertSuccessful();
});

test('encounter resource is registered', function () {
    expect(EncounterResource::getModel())->toBe(Encounter::class);
});
