<?php

use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->staff = User::factory()->staff()->create();
    $this->optometrist = User::factory()->optometrist()->create();
    $this->admin = User::factory()->admin()->create();
    $this->adminOptometrist = User::factory()->optometrist()->create();
    $this->adminOptometrist->roles()->attach(
        Role::query()->where('name', 'admin')->firstOrFail()
    );
    $this->patient = User::factory()->patient()->create();
});

// --- View ---

test('staff can view encounters', function () {
    $encounter = Encounter::factory()->create();

    expect($this->staff->can('view', $encounter))->toBeTrue();
});

test('optometrist can view encounters', function () {
    $encounter = Encounter::factory()->create();

    expect($this->optometrist->can('view', $encounter))->toBeTrue();
});

test('admin can view encounters', function () {
    $encounter = Encounter::factory()->create();

    expect($this->admin->can('view', $encounter))->toBeTrue();
});

test('patient cannot view encounters', function () {
    $encounter = Encounter::factory()->create();

    expect($this->patient->can('view', $encounter))->toBeFalse();
});

// --- Assign ---

test('staff can assign planned encounter', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Planned]);

    expect($this->staff->can('assign', $encounter))->toBeTrue();
});

test('optometrist can assign planned encounter', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Planned]);

    expect($this->optometrist->can('assign', $encounter))->toBeTrue();
});

test('admin can assign planned encounter', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Planned]);

    expect($this->admin->can('assign', $encounter))->toBeTrue();
});

test('patient cannot assign planned encounter', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Planned]);

    expect($this->patient->can('assign', $encounter))->toBeFalse();
});

// --- Start ---

test('optometrist can start encounter', function () {
    $encounter = Encounter::factory()->create([
        'status' => EncounterStatus::Planned,
        'optometrist_id' => $this->optometrist->id,
    ]);

    expect($this->optometrist->can('start', $encounter))->toBeTrue();
});

test('admin optometrist can start encounter', function () {
    $encounter = Encounter::factory()->create([
        'status' => EncounterStatus::Planned,
        'optometrist_id' => $this->adminOptometrist->id,
    ]);

    expect($this->adminOptometrist->can('start', $encounter))->toBeTrue();
});

test('staff cannot start encounter', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Planned]);

    expect($this->staff->can('start', $encounter))->toBeFalse();
});

test('plain admin cannot start encounter', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Planned]);

    expect($this->admin->can('start', $encounter))->toBeFalse();
});

test('patient cannot start encounter', function () {
    $encounter = Encounter::factory()->create(['status' => EncounterStatus::Planned]);

    expect($this->patient->can('start', $encounter))->toBeFalse();
});

// --- Edit (in-progress clinical draft) ---

test('assigned optometrist can edit in-progress encounter', function () {
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $this->optometrist->id,
    ]);

    expect($this->optometrist->can('edit', $encounter))->toBeTrue();
});

test('non-assigned optometrist cannot edit in-progress encounter', function () {
    $otherOptometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $this->optometrist->id,
    ]);

    expect($otherOptometrist->can('edit', $encounter))->toBeFalse();
});

test('staff cannot edit in-progress encounter', function () {
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $this->optometrist->id,
    ]);

    expect($this->staff->can('edit', $encounter))->toBeFalse();
});

test('plain admin cannot edit in-progress encounter', function () {
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $this->optometrist->id,
    ]);

    expect($this->admin->can('edit', $encounter))->toBeFalse();
});

// --- Complete ---

test('assigned optometrist can complete encounter', function () {
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $this->optometrist->id,
    ]);

    expect($this->optometrist->can('complete', $encounter))->toBeTrue();
});

test('non-assigned optometrist cannot complete encounter', function () {
    $otherOptometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $this->optometrist->id,
    ]);

    expect($otherOptometrist->can('complete', $encounter))->toBeFalse();
});

test('staff cannot complete encounter', function () {
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $this->optometrist->id,
    ]);

    expect($this->staff->can('complete', $encounter))->toBeFalse();
});

test('plain admin cannot complete encounter', function () {
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $this->optometrist->id,
    ]);

    expect($this->admin->can('complete', $encounter))->toBeFalse();
});

// --- Transfer as current provider ---

test('assigned optometrist can transfer encounter', function () {
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $this->optometrist->id,
    ]);

    expect($this->optometrist->can('transfer', $encounter))->toBeTrue();
});

test('admin optometrist can transfer encounter', function () {
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $this->adminOptometrist->id,
    ]);

    expect($this->adminOptometrist->can('transfer', $encounter))->toBeTrue();
});

test('non-assigned optometrist cannot transfer encounter', function () {
    $otherOptometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $this->optometrist->id,
    ]);

    expect($otherOptometrist->can('transfer', $encounter))->toBeFalse();
});

// --- Transfer as administrator ---

test('admin can transfer encounter', function () {
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $this->optometrist->id,
    ]);

    expect($this->admin->can('transferAsAdmin', $encounter))->toBeTrue();
});

test('staff cannot transfer as administrator', function () {
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $this->optometrist->id,
    ]);

    expect($this->staff->can('transferAsAdmin', $encounter))->toBeFalse();
});

// --- Add correction ---

test('original completing optometrist can add correction', function () {
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $this->optometrist->id,
        'completed_by' => $this->optometrist->id,
    ]);

    expect($this->optometrist->can('addCorrection', $encounter))->toBeTrue();
});

test('non-completing optometrist cannot add correction', function () {
    $otherOptometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $this->optometrist->id,
        'completed_by' => $this->optometrist->id,
    ]);

    expect($otherOptometrist->can('addCorrection', $encounter))->toBeFalse();
});

test('staff cannot add correction', function () {
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $this->optometrist->id,
        'completed_by' => $this->optometrist->id,
    ]);

    expect($this->staff->can('addCorrection', $encounter))->toBeFalse();
});

// --- Add supplement ---

test('active optometrist can add supplement', function () {
    $encounter = Encounter::factory()->completed()->create();

    expect($this->optometrist->can('addSupplement', $encounter))->toBeTrue();
});

test('admin optometrist can add supplement', function () {
    $encounter = Encounter::factory()->completed()->create();

    expect($this->adminOptometrist->can('addSupplement', $encounter))->toBeTrue();
});

test('staff cannot add supplement', function () {
    $encounter = Encounter::factory()->completed()->create();

    expect($this->staff->can('addSupplement', $encounter))->toBeFalse();
});

test('plain admin cannot add supplement', function () {
    $encounter = Encounter::factory()->completed()->create();

    expect($this->admin->can('addSupplement', $encounter))->toBeFalse();
});

// --- Print ---

test('staff can print completed encounter', function () {
    $encounter = Encounter::factory()->completed()->create();

    expect($this->staff->can('print', $encounter))->toBeTrue();
});

test('optometrist can print completed encounter', function () {
    $encounter = Encounter::factory()->completed()->create();

    expect($this->optometrist->can('print', $encounter))->toBeTrue();
});

test('admin can print completed encounter', function () {
    $encounter = Encounter::factory()->completed()->create();

    expect($this->admin->can('print', $encounter))->toBeTrue();
});

test('patient cannot print encounter', function () {
    $encounter = Encounter::factory()->completed()->create();

    expect($this->patient->can('print', $encounter))->toBeFalse();
});

// --- Inactive accounts ---

test('inactive optometrist cannot start encounter', function () {
    $inactiveOptometrist = User::factory()->optometrist()->create(['is_active' => false]);
    $encounter = Encounter::factory()->create([
        'status' => EncounterStatus::Planned,
        'optometrist_id' => $inactiveOptometrist->id,
    ]);

    expect($inactiveOptometrist->can('start', $encounter))->toBeFalse();
});

test('inactive optometrist cannot edit encounter', function () {
    $inactiveOptometrist = User::factory()->optometrist()->create(['is_active' => false]);
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $inactiveOptometrist->id,
    ]);

    expect($inactiveOptometrist->can('edit', $encounter))->toBeFalse();
});

test('inactive optometrist cannot complete encounter', function () {
    $inactiveOptometrist = User::factory()->optometrist()->create(['is_active' => false]);
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $inactiveOptometrist->id,
    ]);

    expect($inactiveOptometrist->can('complete', $encounter))->toBeFalse();
});
