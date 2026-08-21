<?php

use App\Actions\Encounters\CheckInAppointment;
use App\Actions\Encounters\SaveEncounterDraft;
use App\Actions\Encounters\StartEncounter;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\User;
use Database\Seeders\AppointmentStatusSeeder;
use Database\Seeders\AppointmentTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(AppointmentStatusSeeder::class);
    $this->seed(AppointmentTypeSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->optometrist = User::factory()->optometrist()->create();
});

function createInProgressEncounter($optometrist, $staff): Encounter
{
    $appointment = Appointment::factory()->create();
    app(CheckInAppointment::class)->handle($appointment);
    $encounter = Encounter::query()->where('appointment_id', $appointment->id)->firstOrFail();
    $encounter->update(['optometrist_id' => $optometrist->id]);

    return app(StartEncounter::class)->handle(
        encounter: $encounter->fresh(),
        actor: $optometrist,
    );
}

test('assigned optometrist can save in-progress draft', function () {
    $encounter = createInProgressEncounter($this->optometrist, $this->staff);

    $encounter = app(SaveEncounterDraft::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        data: [
            'chief_complaint' => 'Blurred vision',
            'findings' => null,
            'assessment' => null,
            'plan' => null,
        ],
        lastWizardStep: 1,
    );

    expect($encounter->chief_complaint)->toBe('Blurred vision')
        ->and($encounter->findings)->toBeNull()
        ->and($encounter->last_wizard_step)->toBe(1)
        ->and($encounter->draft_saved_at)->not->toBeNull();
});

test('non-assigned optometrist cannot save draft', function () {
    $encounter = createInProgressEncounter($this->optometrist, $this->staff);
    $otherOptometrist = User::factory()->optometrist()->create();

    app(SaveEncounterDraft::class)->handle(
        encounter: $encounter,
        actor: $otherOptometrist,
        data: ['chief_complaint' => 'Test'],
        lastWizardStep: 1,
    );
})->throws(ValidationException::class, 'Only the assigned optometrist can save this consultation draft.');

test('staff cannot save draft', function () {
    $encounter = createInProgressEncounter($this->optometrist, $this->staff);

    app(SaveEncounterDraft::class)->handle(
        encounter: $encounter,
        actor: $this->staff,
        data: ['chief_complaint' => 'Test'],
        lastWizardStep: 1,
    );
})->throws(ValidationException::class, 'Only an optometrist can save consultation drafts.');

test('plain admin cannot save draft', function () {
    $encounter = createInProgressEncounter($this->optometrist, $this->staff);
    $admin = User::factory()->admin()->create();

    app(SaveEncounterDraft::class)->handle(
        encounter: $encounter,
        actor: $admin,
        data: ['chief_complaint' => 'Test'],
        lastWizardStep: 1,
    );
})->throws(ValidationException::class, 'Only an optometrist can save consultation drafts.');

test('inactive optometrist cannot save draft', function () {
    $encounter = createInProgressEncounter($this->optometrist, $this->staff);
    $this->optometrist->update(['is_active' => false]);

    app(SaveEncounterDraft::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist->fresh(),
        data: ['chief_complaint' => 'Test'],
        lastWizardStep: 1,
    );
})->throws(ValidationException::class, 'Inactive accounts cannot save consultation drafts.');

test('narrative is trimmed before saving', function () {
    $encounter = createInProgressEncounter($this->optometrist, $this->staff);

    $encounter = app(SaveEncounterDraft::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        data: ['chief_complaint' => '  Blurred vision  '],
        lastWizardStep: 1,
    );

    expect($encounter->chief_complaint)->toBe('Blurred vision');
});

test('narrative is capped at 10000 characters', function () {
    $encounter = createInProgressEncounter($this->optometrist, $this->staff);
    $longText = str_repeat('a', 10001);

    app(SaveEncounterDraft::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        data: ['chief_complaint' => $longText],
        lastWizardStep: 1,
    );
})->throws(ValidationException::class);

test('last_wizard_step accepts values 1 through 4', function () {
    $encounter = createInProgressEncounter($this->optometrist, $this->staff);

    foreach ([1, 2, 3, 4] as $step) {
        $encounter = app(SaveEncounterDraft::class)->handle(
            encounter: $encounter->fresh(),
            actor: $this->optometrist,
            data: [],
            lastWizardStep: $step,
        );

        expect($encounter->last_wizard_step)->toBe($step);
    }
});

test('last_wizard_step rejects values outside 1-4', function () {
    $encounter = createInProgressEncounter($this->optometrist, $this->staff);

    app(SaveEncounterDraft::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        data: [],
        lastWizardStep: 0,
    );
})->throws(ValidationException::class);

test('last_wizard_step rejects value 5', function () {
    $encounter = createInProgressEncounter($this->optometrist, $this->staff);

    app(SaveEncounterDraft::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        data: [],
        lastWizardStep: 5,
    );
})->throws(ValidationException::class);

test('incomplete draft is valid without required completion fields', function () {
    $encounter = createInProgressEncounter($this->optometrist, $this->staff);

    // Save a draft with only chief_complaint - no findings, assessment, or plan
    $encounter = app(SaveEncounterDraft::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        data: [
            'chief_complaint' => 'Eye strain',
            'findings' => null,
            'assessment' => null,
            'plan' => null,
        ],
        lastWizardStep: 1,
    );

    expect($encounter->chief_complaint)->toBe('Eye strain')
        ->and($encounter->findings)->toBeNull()
        ->and($encounter->assessment)->toBeNull()
        ->and($encounter->plan)->toBeNull();
});

test('draft saves all clinical fields', function () {
    $encounter = createInProgressEncounter($this->optometrist, $this->staff);

    $encounter = app(SaveEncounterDraft::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        data: [
            'chief_complaint' => 'Blurred vision',
            'past_ocular_history' => 'Previous LASIK',
            'past_surgical_history' => 'Appendectomy',
            'past_medical_history' => 'Hypertension',
            'allergies' => 'Penicillin',
            'medications' => 'Lisinopril',
            'findings' => 'Normal anterior segment',
            'supporting_test_results' => 'Tonometry: 15mmHg',
            'remarks' => 'Patient cooperative',
            'assessment' => 'Myopia progression',
            'plan' => 'Update prescription',
        ],
        lastWizardStep: 3,
    );

    expect($encounter->chief_complaint)->toBe('Blurred vision')
        ->and($encounter->past_ocular_history)->toBe('Previous LASIK')
        ->and($encounter->past_surgical_history)->toBe('Appendectomy')
        ->and($encounter->past_medical_history)->toBe('Hypertension')
        ->and($encounter->allergies)->toBe('Penicillin')
        ->and($encounter->medications)->toBe('Lisinopril')
        ->and($encounter->findings)->toBe('Normal anterior segment')
        ->and($encounter->supporting_test_results)->toBe('Tonometry: 15mmHg')
        ->and($encounter->remarks)->toBe('Patient cooperative')
        ->and($encounter->assessment)->toBe('Myopia progression')
        ->and($encounter->plan)->toBe('Update prescription')
        ->and($encounter->last_wizard_step)->toBe(3);
});

test('cannot save draft on planned encounter', function () {
    $appointment = Appointment::factory()->create();
    app(CheckInAppointment::class)->handle($appointment);
    $encounter = Encounter::query()->where('appointment_id', $appointment->id)->firstOrFail();
    $encounter->update(['optometrist_id' => $this->optometrist->id]);

    app(SaveEncounterDraft::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
        data: ['chief_complaint' => 'Test'],
        lastWizardStep: 1,
    );
})->throws(ValidationException::class, 'Only in-progress consultations can have drafts saved.');

test('cannot save draft on completed encounter', function () {
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $this->optometrist->id,
    ]);

    app(SaveEncounterDraft::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        data: ['chief_complaint' => 'Test'],
        lastWizardStep: 1,
    );
})->throws(ValidationException::class);

test('clinical fields remain encrypted after save', function () {
    $encounter = createInProgressEncounter($this->optometrist, $this->staff);

    app(SaveEncounterDraft::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        data: [
            'chief_complaint' => 'Sensitive clinical data',
            'assessment' => 'Confidential assessment',
        ],
        lastWizardStep: 3,
    );

    $raw = DB::table('encounters')
        ->where('id', $encounter->id)
        ->first();

    expect($raw->chief_complaint)->not->toBe('Sensitive clinical data')
        ->and($raw->assessment)->not->toBe('Confidential assessment');

    $fresh = Encounter::find($encounter->id);
    expect($fresh->chief_complaint)->toBe('Sensitive clinical data')
        ->and($fresh->assessment)->toBe('Confidential assessment');
});
