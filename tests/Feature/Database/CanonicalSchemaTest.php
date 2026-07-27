<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('patients are created before appointments in canonical migrations', function () {
    $migrations = collect(glob(database_path('migrations/*.php')))
        ->map(fn (string $path): string => basename($path));

    $patientsMigration = $migrations->first(fn (string $migration): bool => Str::contains($migration, 'create_patients_table'));
    $appointmentsMigration = $migrations->first(fn (string $migration): bool => Str::contains($migration, 'create_appointments_table'));

    expect($patientsMigration)->not->toBeNull()
        ->and($appointmentsMigration)->not->toBeNull()
        ->and(strcmp($patientsMigration, $appointmentsMigration))->toBeLessThan(0);
});

test('appointments are created with required patient type and duration columns', function () {
    $migration = file_get_contents(database_path('migrations/2026_06_06_020917_create_appointments_table.php'));

    expect($migration)->toContain("foreignId('patient_id')->constrained()->cascadeOnDelete()")
        ->and($migration)->toContain("foreignId('appointment_type_id')->constrained()->restrictOnDelete()")
        ->and($migration)->toContain("unsignedSmallInteger('duration_minutes')->default(30)")
        ->and($migration)->not->toContain("unsignedBigInteger('patient_id')->nullable()")
        ->and($migration)->not->toContain("foreignId('appointment_type_id')->nullable()");
});

test('appointment database columns and foreign keys match canonical constraints', function () {
    $columns = collect(DB::select("
        SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'appointments'
            AND COLUMN_NAME IN ('patient_id', 'appointment_type_id', 'duration_minutes')
    "))->keyBy('COLUMN_NAME');

    $foreignKeys = collect(DB::select("
        SELECT
            KEY_COLUMN_USAGE.COLUMN_NAME,
            KEY_COLUMN_USAGE.REFERENCED_TABLE_NAME,
            REFERENTIAL_CONSTRAINTS.DELETE_RULE
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
        JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            ON KEY_COLUMN_USAGE.CONSTRAINT_SCHEMA = REFERENTIAL_CONSTRAINTS.CONSTRAINT_SCHEMA
            AND KEY_COLUMN_USAGE.CONSTRAINT_NAME = REFERENTIAL_CONSTRAINTS.CONSTRAINT_NAME
            AND KEY_COLUMN_USAGE.TABLE_NAME = REFERENTIAL_CONSTRAINTS.TABLE_NAME
        WHERE REFERENTIAL_CONSTRAINTS.CONSTRAINT_SCHEMA = DATABASE()
            AND REFERENTIAL_CONSTRAINTS.TABLE_NAME = 'appointments'
            AND KEY_COLUMN_USAGE.COLUMN_NAME IN ('patient_id', 'appointment_type_id')
    "))->keyBy('COLUMN_NAME');

    expect($columns['patient_id']->IS_NULLABLE)->toBe('NO')
        ->and($columns['appointment_type_id']->IS_NULLABLE)->toBe('NO')
        ->and($columns['duration_minutes']->IS_NULLABLE)->toBe('NO')
        ->and($columns['duration_minutes']->COLUMN_DEFAULT)->toBe('30')
        ->and($foreignKeys['patient_id']->REFERENCED_TABLE_NAME)->toBe('patients')
        ->and($foreignKeys['patient_id']->DELETE_RULE)->toBe('CASCADE')
        ->and($foreignKeys['appointment_type_id']->REFERENCED_TABLE_NAME)->toBe('appointment_types')
        ->and($foreignKeys['appointment_type_id']->DELETE_RULE)->toBe('RESTRICT');
});
