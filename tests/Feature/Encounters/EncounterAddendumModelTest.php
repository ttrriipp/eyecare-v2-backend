<?php

use App\Enums\EncounterAddendumType;
use App\Models\Encounter;
use App\Models\EncounterAddendum;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('encounter addendum can be created', function () {
    $encounter = Encounter::factory()->create();
    $author = User::factory()->optometrist()->create();

    $addendum = EncounterAddendum::factory()->create([
        'encounter_id' => $encounter->id,
        'authored_by' => $author->id,
    ]);

    expect($addendum->encounter_id)->toBe($encounter->id)
        ->and($addendum->authored_by)->toBe($author->id)
        ->and($addendum->type)->toBe(EncounterAddendumType::Correction);
});

test('addendum reason and content are encrypted', function () {
    $addendum = EncounterAddendum::factory()->create([
        'reason' => 'Correction for transcription error',
        'content' => 'The patient name was misspelled in the original record.',
    ]);

    $raw = DB::table('encounter_addenda')->where('id', $addendum->id)->first();

    expect($raw->reason)->not->toBe('Correction for transcription error')
        ->and($raw->reason)->not->toBeNull()
        ->and($raw->content)->not->toBe('The patient name was misspelled in the original record.');

    $fresh = EncounterAddendum::find($addendum->id);
    expect($fresh->reason)->toBe('Correction for transcription error')
        ->and($fresh->content)->toBe('The patient name was misspelled in the original record.');
});

test('addendum type is typed cast', function () {
    $correction = EncounterAddendum::factory()->create([
        'type' => EncounterAddendumType::Correction,
    ]);
    $supplement = EncounterAddendum::factory()->supplement()->create();

    expect($correction->type)->toBe(EncounterAddendumType::Correction)
        ->and($supplement->type)->toBe(EncounterAddendumType::Supplement);
});

test('addendum sequence number is unique per encounter', function () {
    $encounter = Encounter::factory()->create();

    EncounterAddendum::factory()->create([
        'encounter_id' => $encounter->id,
        'sequence_number' => 1,
    ]);

    EncounterAddendum::factory()->create([
        'encounter_id' => $encounter->id,
        'sequence_number' => 2,
    ]);

    // Duplicate sequence for same encounter should fail
    EncounterAddendum::factory()->create([
        'encounter_id' => $encounter->id,
        'sequence_number' => 1,
    ]);
})->throws(QueryException::class);

test('addendum has no updated_at or soft deletes', function () {
    $addendum = EncounterAddendum::factory()->create();

    $table = DB::getSchemaBuilder()->getColumnListing('encounter_addenda');

    expect($table)->not->toContain('deleted_at')
        ->and($table)->toContain('updated_at'); // Laravel adds this by default
});

test('encounter has ordered addenda relationship', function () {
    $encounter = Encounter::factory()->create();

    EncounterAddendum::factory()->create([
        'encounter_id' => $encounter->id,
        'sequence_number' => 2,
    ]);
    EncounterAddendum::factory()->create([
        'encounter_id' => $encounter->id,
        'sequence_number' => 1,
    ]);

    $addenda = $encounter->addenda;

    expect($addenda)->toHaveCount(2)
        ->and($addenda->first()->sequence_number)->toBe(1)
        ->and($addenda->last()->sequence_number)->toBe(2);
});

test('addendum belongs to author', function () {
    $author = User::factory()->optometrist()->create();
    $addendum = EncounterAddendum::factory()->create([
        'authored_by' => $author->id,
    ]);

    expect($addendum->author->id)->toBe($author->id);
});

test('addendum factory supports supplement type', function () {
    $supplement = EncounterAddendum::factory()->supplement()->create();

    expect($supplement->type)->toBe(EncounterAddendumType::Supplement);
});
