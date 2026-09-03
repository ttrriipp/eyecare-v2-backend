<?php

use App\Models\Encounter;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('new consultations use the consultation number format', function (): void {
    $encounter = Encounter::query()->create([
        'patient_id' => Patient::factory()->create()->id,
    ]);

    expect($encounter->encounter_number)
        ->toMatch('/^CON-\d{4}-\d{6}$/');
});

test('the migration renames existing consultation numbers and preserves encounter ids', function (): void {
    $patient = Patient::factory()->create();
    $first = Encounter::query()->create([
        'patient_id' => $patient->id,
        'encounter_number' => 'ENC-000123',
        'created_at' => Carbon::parse('2026-01-02 09:00:00'),
    ]);
    $second = Encounter::query()->create([
        'patient_id' => $patient->id,
        'encounter_number' => 'ENC-000124',
        'created_at' => Carbon::parse('2026-01-02 10:00:00'),
    ]);
    $firstId = $first->id;
    $secondId = $second->id;

    $migration = require database_path('migrations/2026_09_02_232411_rename_encounter_numbers_to_consultation_format.php');
    $migration->up();

    expect($first->fresh()->encounter_number)->toBe('CON-2026-000001')
        ->and($second->fresh()->encounter_number)->toBe('CON-2026-000002')
        ->and($first->fresh()->id)->toBe($firstId)
        ->and($second->fresh()->id)->toBe($secondId);
});
