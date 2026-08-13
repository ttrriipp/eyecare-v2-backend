<?php

use App\Actions\Encounters\VoidEncounter;
use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->staff = User::factory()->staff()->create();
    $this->optometrist = User::factory()->optometrist()->create();
    $this->admin = User::factory()->admin()->create();
});

// --- Authorization ---

test('an optometrist may void a completed encounter', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Completed]);

    $voided = app(VoidEncounter::class)->handle($encounter, $this->optometrist, 'Duplicate record');

    expect($voided->status)->toBe(EncounterStatus::Voided)
        ->and($voided->voided_by)->toBe($this->optometrist->id)
        ->and($voided->voided_at)->not->toBeNull()
        ->and($voided->void_reason)->toBe('Duplicate record');
});

test('an administrator may void an encounter raised in error', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Planned]);

    $voided = app(VoidEncounter::class)->handle($encounter, $this->admin, 'Double check-in');

    expect($voided->status)->toBe(EncounterStatus::Voided);
});

test('non-clinical staff may not void an encounter', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Completed]);

    app(VoidEncounter::class)->handle($encounter, $this->staff, 'Should not be allowed');
})->throws(ValidationException::class);

test('a deactivated optometrist may not void an encounter', function () {
    $this->optometrist->update(['is_active' => false]);
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Completed]);

    app(VoidEncounter::class)->handle($encounter, $this->optometrist->fresh(), 'Should not be allowed');
})->throws(ValidationException::class);

// --- Status guard ---

test('an in-progress encounter may not be voided', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::InProgress]);

    app(VoidEncounter::class)->handle($encounter, $this->optometrist, 'Mid-encounter');
})->throws(ValidationException::class);

// --- Audit trail ---

test('voiding writes an audit log entry naming the actor and reason', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Completed]);

    app(VoidEncounter::class)->handle($encounter, $this->optometrist, 'Wrong patient selected');

    $this->assertDatabaseHas('audit_logs', [
        'subject_type' => Encounter::class,
        'subject_id' => $encounter->id,
        'action' => 'encounter.voided',
        'actor_id' => $this->optometrist->id,
    ]);
});

// --- Downstream effects ---

test('voiding an encounter does not void the prescription it produced', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Completed]);
    $prescription = Prescription::factory()->create(['encounter_id' => $encounter->id]);

    app(VoidEncounter::class)->handle($encounter, $this->optometrist, 'Wrong patient selected');

    // The patient may already hold the printout, so the prescription stands.
    // Staff are warned on the prescription page instead — see ViewPrescription.
    expect($prescription->fresh()->isVoided())->toBeFalse();
});

// --- Policy parity with the action ---

test('the void policy matches who the action allows', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Completed]);

    expect($this->optometrist->can('void', $encounter))->toBeTrue()
        ->and($this->admin->can('void', $encounter))->toBeTrue()
        ->and($this->staff->can('void', $encounter))->toBeFalse();
});
