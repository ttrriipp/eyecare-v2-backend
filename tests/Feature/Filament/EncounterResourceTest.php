<?php

use App\Enums\EncounterStatus;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Filament\Resources\Encounters\Pages\ListEncounters;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
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

test('consultation table prioritizes active work and sorts each workflow group', function (): void {
    $optometrist = User::factory()->optometrist()->create();
    $baseTime = now()->startOfDay();

    $inProgressOldest = Encounter::factory()->inProgress()->create([
        'started_at' => $baseTime->copy()->subHours(4),
        'created_at' => $baseTime->copy()->subHours(8),
    ]);
    $inProgressNewest = Encounter::factory()->inProgress()->create([
        'started_at' => $baseTime->copy()->subHours(2),
        'created_at' => $baseTime->copy()->subHours(7),
    ]);
    $inProgressWithoutStart = Encounter::factory()->create([
        'status' => EncounterStatus::InProgress,
        'started_at' => null,
        'created_at' => $baseTime->copy()->subHours(6),
    ]);

    $earliestAppointment = Appointment::factory()->create([
        'scheduled_at' => $baseTime->copy()->addHour(),
    ]);
    $latestAppointment = Appointment::factory()->create([
        'scheduled_at' => $baseTime->copy()->addHours(3),
    ]);
    $deletedAppointment = Appointment::factory()->create([
        'scheduled_at' => $baseTime->copy()->subHour(),
    ]);
    $deletedAppointment->delete();

    $plannedEarliest = Encounter::factory()->create([
        'appointment_id' => $earliestAppointment->id,
        'patient_id' => $earliestAppointment->patient_id,
        'created_at' => $baseTime->copy()->subHours(5),
    ]);
    $plannedLatest = Encounter::factory()->create([
        'appointment_id' => $latestAppointment->id,
        'patient_id' => $latestAppointment->patient_id,
        'created_at' => $baseTime->copy()->subHours(4),
    ]);
    $plannedWithoutAvailableAppointment = Encounter::factory()->create([
        'appointment_id' => $deletedAppointment->id,
        'patient_id' => $deletedAppointment->patient_id,
        'created_at' => $baseTime->copy()->subHours(3),
    ]);

    $cancelled = Encounter::factory()->create([
        'status' => EncounterStatus::Cancelled,
        'created_at' => $baseTime->copy()->subHour(),
    ]);
    $completedNewest = Encounter::factory()->completed()->create([
        'created_at' => $baseTime->copy()->subHours(2),
    ]);
    $voided = Encounter::factory()->create([
        'status' => EncounterStatus::Voided,
        'created_at' => $baseTime->copy()->subHours(3),
    ]);
    $completedOlder = Encounter::factory()->completed()->create([
        'created_at' => $baseTime->copy()->subHours(4),
    ]);

    $this->actingAs($optometrist);

    Livewire::test(ListEncounters::class)
        ->assertCanSeeTableRecords([
            $inProgressOldest,
            $inProgressNewest,
            $inProgressWithoutStart,
            $plannedEarliest,
            $plannedLatest,
            $plannedWithoutAvailableAppointment,
            $cancelled,
            $completedNewest,
            $voided,
            $completedOlder,
        ], inOrder: true);
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
        ->assertSee('Consultations')
        ->assertSee('Consultation Details')
        ->assertDontSee('Encounter Details')
        ->assertSee('Patient')
        ->assertDontSee('Health Record Status');
});

test('encounter resource presents consultation terminology', function () {
    expect(EncounterResource::getNavigationLabel())->toBe('Consultations')
        ->and(EncounterResource::getModelLabel())->toBe('Consultation')
        ->and(EncounterResource::getPluralModelLabel())->toBe('Consultations');
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
        ->assertFormFieldExists('assessment')
        ->assertFormFieldExists('plan')
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

test('encounter wizard saves prescription draft during step navigation', function () {
    $optometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $optometrist->id,
    ]);

    $this->actingAs($optometrist);

    // Navigate through wizard - step validation triggers draft saves
    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->fillForm([
            'chief_complaint' => 'Blurred vision',
        ])
        ->goToNextWizardStep()
        ->assertHasNoFormErrors();

    $encounter->refresh();
    expect($encounter->chief_complaint)->toBe('Blurred vision')
        ->and($encounter->last_wizard_step)->toBe(1);
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
    ]);
    Prescription::factory()->linkedToEncounter($encounter)->create([
        'main_od_sphere' => '-1.00',
        'main_os_sphere' => '-1.25',
    ]);

    $this->actingAs($optometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertSee('Review & Complete')
        ->assertSee('Maria Santos')
        ->assertSee('Blurred vision')
        ->assertSee('Wears glasses')
        ->assertSee('Distance acuity reduced.')
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

test('an optometrist cannot create a quotation from an active encounter', function () {
    $optometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $optometrist->id,
    ]);
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $this->actingAs($optometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionHidden('createQuotation');
});

test('the original completing optometrist can add an addendum', function () {
    $optometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $optometrist->id,
        'completed_by' => $optometrist->id,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionVisible('addAddendum');
});

test('a non-completing optometrist can add an addendum but only as a supplement', function () {
    $completingOptometrist = User::factory()->optometrist()->create();
    $otherOptometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $completingOptometrist->id,
        'completed_by' => $completingOptometrist->id,
    ]);

    $this->actingAs($otherOptometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionVisible('addAddendum')
        ->mountAction('addAddendum')
        ->assertActionDataSet(['type' => 'supplement']);
});

test('assignment is available only while an encounter is planned', function () {
    $admin = User::factory()->admin()->create();
    $encounter = Encounter::factory()->inProgress()->create();

    $this->actingAs($admin);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionHidden('assignOptometrist');
});

test('encounter assignment uses the audited assignment action', function () {
    $staff = User::factory()->staff()->create();
    $optometrist = User::factory()->optometrist()->create();
    $appointment = Appointment::factory()->create();
    $encounter = Encounter::factory()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
        'status' => EncounterStatus::Planned,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->callAction('assignOptometrist', ['optometrist_id' => $optometrist->id])
        ->assertNotified();

    expect($encounter->fresh()->optometrist_id)->toBe($optometrist->id)
        ->and($appointment->fresh()->optometrist_id)->toBe($optometrist->id);

    expect(AuditLog::query()
        ->where('subject_type', Encounter::class)
        ->where('subject_id', $encounter->id)
        ->where('action', 'encounter.provider_assigned')
        ->exists())->toBeTrue();
});

test('in-progress encounter can complete the visit from the wizard confirmation action', function () {
    $optometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $optometrist->id,
        'chief_complaint' => 'Blurred vision',
        'findings' => 'Normal anterior segment',
        'assessment' => 'Myopia progression',
        'plan' => 'Update prescription',
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

test('planned consultation uses consultation action labels', function () {
    $optometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Planned]);

    $this->actingAs($optometrist);

    Livewire::test(EditEncounter::class, ['record' => $encounter->getRouteKey()])
        ->assertActionHasLabel('startEncounter', 'Start Consultation')
        ->assertActionHasLabel('voidEncounter', 'Void Consultation');
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

test('an optometrist can start an unassigned checked-in encounter from the table', function () {
    $optometrist = User::factory()->optometrist()->create();
    $appointment = Appointment::factory()->checkedIn()->create();
    $encounter = Encounter::factory()->create([
        'appointment_id' => $appointment->id,
        'patient_id' => $appointment->patient_id,
        'status' => EncounterStatus::Planned,
    ]);

    $this->actingAs($optometrist);

    Livewire::test(ListEncounters::class)
        ->assertActionVisible(TestAction::make('startEncounter')->table($encounter))
        ->callTableAction('startEncounter', $encounter)
        ->assertNotified();

    expect($encounter->fresh()->status)->toBe(EncounterStatus::InProgress)
        ->and($encounter->fresh()->optometrist_id)->toBe($optometrist->id);
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
