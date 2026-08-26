<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('saved frames contract migration refuses to drop non-empty reservation tables', function () {
    Schema::create('frame_reservations', function ($table): void {
        $table->id();
    });
    Schema::create('frame_reservation_items', function ($table): void {
        $table->id();
    });
    DB::table('frame_reservations')->insert(['id' => 1]);

    $migration = require database_path('migrations/2026_08_26_194312_drop_frame_reservation_tables.php');

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'Run saved-frames:migrate-reservations --execute first.');

    Schema::dropIfExists('frame_reservation_items');
    Schema::dropIfExists('frame_reservations');
});

test('saved frames contract migration down path restores the final reservation schema', function () {
    $migration = require database_path('migrations/2026_08_26_194312_drop_frame_reservation_tables.php');

    $migration->down();

    $columns = collect(DB::select(<<<'SQL'
        SELECT COLUMN_NAME, IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'frame_reservations'
          AND COLUMN_NAME = 'appointment_id'
    SQL))->keyBy('COLUMN_NAME');

    $foreignKey = DB::selectOne(<<<'SQL'
        SELECT REFERENTIAL_CONSTRAINTS.DELETE_RULE
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
        JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE
          ON KEY_COLUMN_USAGE.CONSTRAINT_SCHEMA = REFERENTIAL_CONSTRAINTS.CONSTRAINT_SCHEMA
         AND KEY_COLUMN_USAGE.CONSTRAINT_NAME = REFERENTIAL_CONSTRAINTS.CONSTRAINT_NAME
         AND KEY_COLUMN_USAGE.TABLE_NAME = REFERENTIAL_CONSTRAINTS.TABLE_NAME
        WHERE REFERENTIAL_CONSTRAINTS.CONSTRAINT_SCHEMA = DATABASE()
          AND REFERENTIAL_CONSTRAINTS.TABLE_NAME = 'frame_reservations'
          AND KEY_COLUMN_USAGE.COLUMN_NAME = 'appointment_id'
    SQL);

    expect($columns['appointment_id']->IS_NULLABLE)->toBe('NO')
        ->and($foreignKey->DELETE_RULE)->toBe('RESTRICT');

    Schema::dropIfExists('frame_reservation_items');
    Schema::dropIfExists('frame_reservations');
});
