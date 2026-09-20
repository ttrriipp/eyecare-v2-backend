<?php

use App\Actions\Encounters\CancelEncounter;
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

test('an optometrist may cancel a completed encounter', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Completed]);

    $cancelled = app(CancelEncounter::class)->handle($encounter, $this->optometrist, 'Duplicate record');

    expect($cancelled->status)->toBe(EncounterStatus::Cancelled)
        ->and($cancelled->cancelled_by)->toBe($this->optometrist->id)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->cancellation_reason)->toBe('Duplicate record');
});

test('an administrator may cancel an encounter raised in error', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Planned]);

    $cancelled = app(CancelEncounter::class)->handle($encounter, $this->admin, 'Double check-in');

    expect($cancelled->status)->toBe(EncounterStatus::Cancelled);
});

test('non-clinical staff may not cancel an encounter', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Completed]);

    app(CancelEncounter::class)->handle($encounter, $this->staff, 'Should not be allowed');
})->throws(ValidationException::class, 'Only an optometrist or administrator may cancel a consultation.');

test('a deactivated optometrist may not cancel an encounter', function () {
    $this->optometrist->update(['is_active' => false]);
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Completed]);

    app(CancelEncounter::class)->handle($encounter, $this->optometrist->fresh(), 'Should not be allowed');
})->throws(ValidationException::class, 'Only an optometrist or administrator may cancel a consultation.');

// --- Status guard ---

test('an in-progress encounter may not be cancelled', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::InProgress]);

    app(CancelEncounter::class)->handle($encounter, $this->optometrist, 'Mid-encounter');
})->throws(ValidationException::class, 'Only planned or completed consultations can be cancelled.');

// --- Audit trail ---

test('cancelling writes an audit log entry naming the actor and reason', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Completed]);

    app(CancelEncounter::class)->handle($encounter, $this->optometrist, 'Wrong patient selected');

    $this->assertDatabaseHas('audit_logs', [
        'subject_type' => Encounter::class,
        'subject_id' => $encounter->id,
        'action' => 'encounter.cancelled',
        'actor_id' => $this->optometrist->id,
    ]);
});

// --- Downstream effects ---

test('cancelling an encounter does not cancel the prescription it produced', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Completed]);
    $prescription = Prescription::factory()->create(['encounter_id' => $encounter->id]);

    app(CancelEncounter::class)->handle($encounter, $this->optometrist, 'Wrong patient selected');

    // The patient may already hold the printout, so the prescription stands.
    // Staff are warned on the prescription page instead — see ViewPrescription.
    expect($prescription->fresh()->isCancelled())->toBeFalse();
});

// --- Policy parity with the action ---

test('the cancel policy matches who the action allows', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Completed]);

    expect($this->optometrist->can('cancel', $encounter))->toBeTrue()
        ->and($this->admin->can('cancel', $encounter))->toBeTrue()
        ->and($this->staff->can('cancel', $encounter))->toBeFalse();
});
