<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

test('prescriptions are created with patient ownership and encryption-compatible columns', function () {
    $migration = file_get_contents(database_path('migrations/2026_06_09_063305_create_prescriptions_table.php'));

    expect($migration)->toContain("foreignId('patient_id')->nullable()->constrained()->cascadeOnDelete()")
        ->and($migration)->toContain("foreignId('encounter_id')->nullable()")
        ->and($migration)->toContain("text('od_sphere')->nullable()")
        ->and($migration)->toContain("text('os_sphere')->nullable()")
        ->and($migration)->toContain("text('pd')->nullable()")
        ->and($migration)->toContain("text('notes')->nullable()")
        ->and($migration)->not->toContain('customer_id')
        ->and(file_exists(database_path('migrations/2026_07_25_210000_link_prescriptions_to_encounters.php')))->toBeFalse()
        ->and(file_exists(database_path('migrations/2026_06_29_212317_encrypt_prescription_sensitive_columns.php')))->toBeFalse()
        ->and(file_exists(database_path('migrations/2026_06_29_231703_drop_prescription_uploads_table.php')))->toBeFalse();
});

test('prescription database columns and foreign keys match canonical constraints', function () {
    $columns = collect(DB::select("
        SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'prescriptions'
            AND COLUMN_NAME IN (
                'customer_id',
                'patient_id',
                'encounter_id',
                'od_sphere',
                'os_sphere',
                'pd',
                'notes',
                'last_expiry_notified_at'
            )
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
            AND REFERENTIAL_CONSTRAINTS.TABLE_NAME = 'prescriptions'
            AND KEY_COLUMN_USAGE.COLUMN_NAME IN ('patient_id', 'encounter_id')
    "))->keyBy('COLUMN_NAME');

    expect($columns)->not->toHaveKey('customer_id')
        ->and($columns['patient_id']->IS_NULLABLE)->toBe('YES')
        ->and($columns['encounter_id']->IS_NULLABLE)->toBe('YES')
        ->and($columns['od_sphere']->DATA_TYPE)->toBe('text')
        ->and($columns['os_sphere']->DATA_TYPE)->toBe('text')
        ->and($columns['pd']->DATA_TYPE)->toBe('text')
        ->and($columns['notes']->DATA_TYPE)->toBe('text')
        ->and($columns['last_expiry_notified_at']->IS_NULLABLE)->toBe('YES')
        ->and($foreignKeys['patient_id']->REFERENCED_TABLE_NAME)->toBe('patients')
        ->and($foreignKeys['patient_id']->DELETE_RULE)->toBe('CASCADE')
        ->and($foreignKeys['encounter_id']->REFERENCED_TABLE_NAME)->toBe('encounters')
        ->and($foreignKeys['encounter_id']->DELETE_RULE)->toBe('SET NULL');
});

test('conversations are created with a canonical patient owner only', function () {
    $createMigration = file_get_contents(database_path('migrations/2026_06_10_134402_create_conversations_table.php'));
    $reworkMigration = file_get_contents(database_path('migrations/2026_06_16_132305_rework_messaging_schema.php'));

    expect($createMigration)->toContain("foreignId('patient_id')->unique()->constrained()->cascadeOnDelete()")
        ->and($createMigration)->not->toContain('customer_id')
        ->and($createMigration)->not->toContain('staff_id')
        ->and($createMigration)->not->toContain('appointment_id')
        ->and($createMigration)->not->toContain('order_id')
        ->and($createMigration)->not->toContain('subject')
        ->and($reworkMigration)->toContain("Schema::create('message_context_links'")
        ->and($reworkMigration)->not->toContain("Schema::table('conversations'")
        ->and($reworkMigration)->not->toContain('customer_id')
        ->and(file_exists(database_path('migrations/2026_07_27_010000_migrate_feedback_conversations_to_patient_id.php')))->toBeFalse();
});

test('conversation database columns and indexes match canonical constraints', function () {
    $columns = collect(DB::select("
        SELECT COLUMN_NAME, IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'conversations'
            AND COLUMN_NAME IN (
                'customer_id',
                'staff_id',
                'appointment_id',
                'order_id',
                'subject',
                'patient_id'
            )
    "))->keyBy('COLUMN_NAME');

    $indexes = collect(DB::select("
        SELECT INDEX_NAME, NON_UNIQUE
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'conversations'
            AND COLUMN_NAME = 'patient_id'
    "))->keyBy('INDEX_NAME');

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
            AND REFERENTIAL_CONSTRAINTS.TABLE_NAME = 'conversations'
            AND KEY_COLUMN_USAGE.COLUMN_NAME = 'patient_id'
    "))->keyBy('COLUMN_NAME');

    expect($columns)->not->toHaveKeys(['customer_id', 'staff_id', 'appointment_id', 'order_id', 'subject'])
        ->and($columns['patient_id']->IS_NULLABLE)->toBe('NO')
        ->and($indexes->contains(fn (object $index): bool => (int) $index->NON_UNIQUE === 0))->toBeTrue()
        ->and($foreignKeys['patient_id']->REFERENCED_TABLE_NAME)->toBe('patients')
        ->and($foreignKeys['patient_id']->DELETE_RULE)->toBe('CASCADE');
});

test('retired tables are absent from the canonical schema', function () {
    expect(Schema::hasTable('feedback'))->toBeFalse()
        ->and(Schema::hasTable('physical_chart_events'))->toBeFalse()
        ->and(Schema::hasTable('retention_policies'))->toBeFalse()
        ->and(Schema::hasTable('legal_holds'))->toBeFalse()
        ->and(Schema::hasTable('job_batches'))->toBeFalse();
});

test('inventory movements are created with canonical reservation and job order links', function () {
    $migration = file_get_contents(database_path('migrations/2026_06_10_033127_create_inventory_movements_table.php'));

    expect($migration)->toContain("foreignId('reservation_id')->nullable()")
        ->and($migration)->toContain("foreignId('job_order_id')->nullable()")
        ->and($migration)->not->toContain("unsignedBigInteger('order_id')")
        ->and(file_exists(database_path('migrations/2026_07_27_020000_replace_order_id_with_canonical_sources_on_inventory_movements.php')))->toBeFalse();
});

test('inventory movement database columns match canonical constraints', function () {
    $columns = collect(DB::select("
        SELECT COLUMN_NAME, IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'inventory_movements'
            AND COLUMN_NAME IN ('order_id', 'reservation_id', 'job_order_id')
    "))->keyBy('COLUMN_NAME');

    expect($columns)->not->toHaveKey('order_id')
        ->and($columns['reservation_id']->IS_NULLABLE)->toBe('YES')
        ->and($columns['job_order_id']->IS_NULLABLE)->toBe('YES');
});

test('sms notifications never create a legacy order link', function () {
    $createMigration = file_get_contents(database_path('migrations/2026_06_06_021117_create_sms_notifications_table.php'));
    $updateMigration = file_get_contents(database_path('migrations/2026_06_28_061836_add_failure_reason_and_order_id_to_sms_notifications.php'));

    expect($createMigration)->not->toContain('order_id')
        ->and($updateMigration)->toContain("text('failure_reason')->nullable()")
        ->and($updateMigration)->toContain("foreignId('appointment_id')->nullable(false)->change()")
        ->and($updateMigration)->not->toContain('order_id');
});

test('sms notification database columns match canonical constraints', function () {
    $columns = collect(DB::select("
        SELECT COLUMN_NAME, IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'sms_notifications'
            AND COLUMN_NAME IN ('appointment_id', 'order_id', 'failure_reason')
    "))->keyBy('COLUMN_NAME');

    expect($columns)->not->toHaveKey('order_id')
        ->and($columns['appointment_id']->IS_NULLABLE)->toBe('YES')
        ->and($columns['failure_reason']->IS_NULLABLE)->toBe('YES');
});
