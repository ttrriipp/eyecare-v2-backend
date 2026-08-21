<?php

use App\Actions\Encounters\CreateEncounterAddendum;
use App\Enums\EncounterAddendumType;
use App\Models\Encounter;
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
    $this->admin = User::factory()->admin()->create();
});

function createCompletedEncounter(?User $completedBy = null): Encounter
{
    $completedBy ??= test()->optometrist;

    return Encounter::factory()->completed()->create([
        'optometrist_id' => $completedBy->id,
        'completed_by' => $completedBy->id,
    ]);
}

test('completing optometrist can create correction', function () {
    $encounter = createCompletedEncounter($this->optometrist);

    $addendum = app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        type: EncounterAddendumType::Correction,
        reason: 'Transcription error in findings',
        content: 'The original findings section had a typo.',
    );

    expect($addendum->type)->toBe(EncounterAddendumType::Correction)
        ->and($addendum->reason)->toBe('Transcription error in findings')
        ->and($addendum->content)->toBe('The original findings section had a typo.')
        ->and($addendum->authored_by)->toBe($this->optometrist->id)
        ->and($addendum->sequence_number)->toBe(1);
});

test('other optometrist can create supplement', function () {
    $encounter = createCompletedEncounter($this->optometrist);

    $addendum = app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->otherOptometrist,
        type: EncounterAddendumType::Supplement,
        reason: 'Additional observations',
        content: 'Patient mentioned additional symptoms during follow-up call.',
    );

    expect($addendum->type)->toBe(EncounterAddendumType::Supplement)
        ->and($addendum->authored_by)->toBe($this->otherOptometrist->id);
});

test('non-completing optometrist cannot create correction', function () {
    $encounter = createCompletedEncounter($this->optometrist);

    app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->otherOptometrist,
        type: EncounterAddendumType::Correction,
        reason: 'Correction',
        content: 'Content',
    );
})->throws(ValidationException::class);

test('staff cannot create addendum', function () {
    $encounter = createCompletedEncounter();

    app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->staff,
        type: EncounterAddendumType::Supplement,
        reason: 'Reason',
        content: 'Content',
    );
})->throws(ValidationException::class, 'Only optometrists can create consultation addenda.');

test('plain admin cannot create addendum', function () {
    $encounter = createCompletedEncounter();

    app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->admin,
        type: EncounterAddendumType::Supplement,
        reason: 'Reason',
        content: 'Content',
    );
})->throws(ValidationException::class, 'Only optometrists can create consultation addenda.');

test('inactive optometrist cannot create addendum', function () {
    $encounter = createCompletedEncounter($this->optometrist);
    $this->optometrist->update(['is_active' => false]);

    app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->optometrist->fresh(),
        type: EncounterAddendumType::Correction,
        reason: 'Reason',
        content: 'Content',
    );
})->throws(ValidationException::class);

test('cannot add addendum to in-progress encounter', function () {
    $encounter = Encounter::factory()->inProgress()->create([
        'optometrist_id' => $this->optometrist->id,
    ]);

    app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        type: EncounterAddendumType::Correction,
        reason: 'Reason',
        content: 'Content',
    );
})->throws(ValidationException::class, 'Addenda can only be added to completed consultations.');

test('addendum reason is required', function () {
    $encounter = createCompletedEncounter();

    app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        type: EncounterAddendumType::Supplement,
        reason: '',
        content: 'Content',
    );
})->throws(ValidationException::class);

test('addendum content is required', function () {
    $encounter = createCompletedEncounter();

    app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        type: EncounterAddendumType::Supplement,
        reason: 'Reason',
        content: '',
    );
})->throws(ValidationException::class);

test('addendum reason is capped at 1000 characters', function () {
    $encounter = createCompletedEncounter();

    app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        type: EncounterAddendumType::Supplement,
        reason: str_repeat('a', 1001),
        content: 'Content',
    );
})->throws(ValidationException::class);

test('addendum content is capped at 10000 characters', function () {
    $encounter = createCompletedEncounter();

    app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        type: EncounterAddendumType::Supplement,
        reason: 'Reason',
        content: str_repeat('a', 10001),
    );
})->throws(ValidationException::class);

test('sequence numbers are monotonic per encounter', function () {
    $encounter = createCompletedEncounter($this->optometrist);

    $first = app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        type: EncounterAddendumType::Correction,
        reason: 'First',
        content: 'First addendum',
    );

    $second = app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter->fresh(),
        actor: $this->otherOptometrist,
        type: EncounterAddendumType::Supplement,
        reason: 'Second',
        content: 'Second addendum',
    );

    expect($first->sequence_number)->toBe(1)
        ->and($second->sequence_number)->toBe(2);
});

test('addendum creates audit event', function () {
    $encounter = createCompletedEncounter($this->optometrist);

    app(CreateEncounterAddendum::class)->handle(
        encounter: $encounter,
        actor: $this->optometrist,
        type: EncounterAddendumType::Correction,
        reason: 'Correction',
        content: 'Corrected content',
    );

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'encounter.amended',
    ]);
});
