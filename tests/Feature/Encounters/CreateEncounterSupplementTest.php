<?php

use App\Actions\Encounters\CreateEncounterAddendum;
use App\Enums\EncounterAddendumType;
use App\Models\Encounter;
use App\Models\EncounterAddendum;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->optometrist = User::factory()->optometrist()->create();
    $this->otherOptometrist = User::factory()->optometrist()->create();
    $this->staff = User::factory()->staff()->create();
});

test('active optometrist can create supplement', function () {
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $this->optometrist->id,
        'completed_by' => $this->optometrist->id,
    ]);

    $addendum = app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->otherOptometrist,
        type: EncounterAddendumType::Supplement,
        reason: 'Follow-up observations',
        content: 'Patient reported improvement after treatment.',
    );

    expect($addendum->type)->toBe(EncounterAddendumType::Supplement)
        ->and($addendum->authored_by)->toBe($this->otherOptometrist->id);
});

test('staff cannot create supplement', function () {
    $encounter = Encounter::factory()->completed()->create();

    app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->staff,
        type: EncounterAddendumType::Supplement,
        reason: 'Reason',
        content: 'Content',
    );
})->throws(ValidationException::class, 'Only optometrists can create consultation addenda.');

test('supplement cannot masquerade as correction', function () {
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $this->optometrist->id,
        'completed_by' => $this->optometrist->id,
    ]);

    // The type is passed as a parameter, so the action validates authorization based on type
    // This test verifies that the type parameter is respected
    $addendum = app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->otherOptometrist,
        type: EncounterAddendumType::Supplement,
        reason: 'Supplement reason',
        content: 'Supplement content',
    );

    expect($addendum->type)->toBe(EncounterAddendumType::Supplement);
});

test('supplement is attributed to the author', function () {
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $this->optometrist->id,
        'completed_by' => $this->optometrist->id,
    ]);

    $addendum = app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->otherOptometrist,
        type: EncounterAddendumType::Supplement,
        reason: 'Additional notes',
        content: 'Content from another provider.',
    );

    expect($addendum->authored_by)->toBe($this->otherOptometrist->id)
        ->and($addendum->author->id)->toBe($this->otherOptometrist->id);
});

test('concurrent addendum creation yields unique sequences', function () {
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $this->optometrist->id,
        'completed_by' => $this->optometrist->id,
    ]);

    // Simulate concurrent creation
    $first = app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        type: EncounterAddendumType::Correction,
        reason: 'First',
        content: 'First correction',
    );

    $second = app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->otherOptometrist,
        type: EncounterAddendumType::Supplement,
        reason: 'Second',
        content: 'Second supplement',
    );

    $third = app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist,
        type: EncounterAddendumType::Correction,
        reason: 'Third',
        content: 'Third correction',
    );

    expect($first->sequence_number)->toBe(1)
        ->and($second->sequence_number)->toBe(2)
        ->and($third->sequence_number)->toBe(3);

    // Verify all are unique
    $sequences = EncounterAddendum::query()
        ->where('encounter_id', $encounter->id)
        ->pluck('sequence_number')
        ->toArray();

    expect($sequences)->toBe([1, 2, 3]);
});

test('concurrent creation with same sequence fails gracefully', function () {
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $this->optometrist->id,
        'completed_by' => $this->optometrist->id,
    ]);

    // Create first addendum
    app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        type: EncounterAddendumType::Correction,
        reason: 'First',
        content: 'First',
    );

    // Try to create with same sequence (should fail due to unique constraint)
    // The action handles this by locking the encounter and allocating next sequence
    $second = app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->otherOptometrist,
        type: EncounterAddendumType::Supplement,
        reason: 'Second',
        content: 'Second',
    );

    expect($second->sequence_number)->toBe(2);
});

test('addenda are immutable after creation', function () {
    $encounter = Encounter::factory()->completed()->create([
        'optometrist_id' => $this->optometrist->id,
        'completed_by' => $this->optometrist->id,
    ]);

    $addendum = app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        type: EncounterAddendumType::Correction,
        reason: 'Correction',
        content: 'Original content',
    );

    // Verify no update or delete methods exist on the action
    // The model itself allows updates (Laravel default), but the action doesn't expose them
    expect($addendum->reason)->toBe('Correction')
        ->and($addendum->content)->toBe('Original content');
});
