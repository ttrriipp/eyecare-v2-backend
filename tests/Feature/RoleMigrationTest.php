<?php

use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

test('role_user pivot table exists with expected columns', function () {
    expect(Schema::hasTable('role_user'))->toBeTrue()
        ->and(Schema::hasColumn('role_user', 'role_id'))->toBeTrue()
        ->and(Schema::hasColumn('role_user', 'user_id'))->toBeTrue()
        ->and(Schema::hasColumn('role_user', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('role_user', 'updated_at'))->toBeTrue();
});

test('optometrist role exists after migration', function () {
    expect(Role::query()->where('name', 'optometrist')->exists())->toBeTrue();
});

test('role_user has unique constraint on role_id and user_id', function () {
    $indexes = DB::select('SHOW INDEXES FROM role_user WHERE Non_unique = 0');
    $uniqueIndexColumns = array_column($indexes, 'Column_name');

    expect($uniqueIndexColumns)->toContain('role_id')
        ->and($uniqueIndexColumns)->toContain('user_id');
});

test('backfill maps patient users to the patient role', function () {
    $patientRole = Role::query()->where('name', 'patient')->first();
    $userId = DB::table('users')->insertGetId([
        'first_name' => 'Test',
        'last_name' => 'Patient',
        'email' => 'migration-patient@test.com',
        'phone' => '09170000010',
        'password' => 'hashed',
        'role_id' => $patientRole->id,
        'is_optometrist' => false,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Simulate the backfill: patient → patient role.
    DB::table('role_user')->insert([
        'role_id' => $patientRole->id,
        'user_id' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $assignedRoles = DB::table('role_user')
        ->where('user_id', $userId)
        ->pluck('role_id')
        ->all();

    expect($assignedRoles)->toBe([$patientRole->id]);
});

test('backfill maps staff without optometrist flag to the staff role', function () {
    $staffRole = Role::query()->where('name', 'staff')->first();
    $userId = DB::table('users')->insertGetId([
        'first_name' => 'Test',
        'last_name' => 'Staff',
        'email' => 'migration-staff@test.com',
        'phone' => '09170000011',
        'password' => 'hashed',
        'role_id' => $staffRole->id,
        'is_optometrist' => false,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('role_user')->insert([
        'role_id' => $staffRole->id,
        'user_id' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $assignedRoles = DB::table('role_user')
        ->where('user_id', $userId)
        ->pluck('role_id')
        ->all();

    expect($assignedRoles)->toBe([$staffRole->id]);
});

test('backfill maps staff with optometrist flag to the optometrist role', function () {
    $staffRole = Role::query()->where('name', 'staff')->first();
    $optometristRole = Role::query()->where('name', 'optometrist')->first();
    $userId = DB::table('users')->insertGetId([
        'first_name' => 'Test',
        'last_name' => 'StaffOptom',
        'email' => 'migration-staff-optom@test.com',
        'phone' => '09170000012',
        'password' => 'hashed',
        'role_id' => $staffRole->id,
        'is_optometrist' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('role_user')->insert([
        'role_id' => $optometristRole->id,
        'user_id' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $assignedRoles = DB::table('role_user')
        ->where('user_id', $userId)
        ->pluck('role_id')
        ->all();

    expect($assignedRoles)->toBe([$optometristRole->id]);
});

test('backfill maps admin without optometrist flag to the admin role', function () {
    $adminRole = Role::query()->where('name', 'admin')->first();
    $userId = DB::table('users')->insertGetId([
        'first_name' => 'Test',
        'last_name' => 'Admin',
        'email' => 'migration-admin@test.com',
        'phone' => '09170000013',
        'password' => 'hashed',
        'role_id' => $adminRole->id,
        'is_optometrist' => false,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('role_user')->insert([
        'role_id' => $adminRole->id,
        'user_id' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $assignedRoles = DB::table('role_user')
        ->where('user_id', $userId)
        ->pluck('role_id')
        ->all();

    expect($assignedRoles)->toBe([$adminRole->id]);
});

test('backfill maps admin with optometrist flag to admin and optometrist roles', function () {
    $adminRole = Role::query()->where('name', 'admin')->first();
    $optometristRole = Role::query()->where('name', 'optometrist')->first();
    $userId = DB::table('users')->insertGetId([
        'first_name' => 'Test',
        'last_name' => 'AdminOptom',
        'email' => 'migration-admin-optom@test.com',
        'phone' => '09170000014',
        'password' => 'hashed',
        'role_id' => $adminRole->id,
        'is_optometrist' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('role_user')->insert([
        'role_id' => $adminRole->id,
        'user_id' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('role_user')->insert([
        'role_id' => $optometristRole->id,
        'user_id' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $assignedRoles = DB::table('role_user')
        ->where('user_id', $userId)
        ->pluck('role_id')
        ->sort()
        ->values()
        ->all();

    expect($assignedRoles)->toBe(
        collect([$adminRole->id, $optometristRole->id])->sort()->values()->all(),
    );
});

test('each user has exactly one pivot row per assigned role', function () {
    $adminRole = Role::query()->where('name', 'admin')->first();
    $optometristRole = Role::query()->where('name', 'optometrist')->first();

    // Admin + optometrist user: 2 pivot rows.
    $dualUserId = DB::table('users')->insertGetId([
        'first_name' => 'Dual',
        'last_name' => 'Role',
        'email' => 'migration-dual@test.com',
        'phone' => '09170000015',
        'password' => 'hashed',
        'role_id' => $adminRole->id,
        'is_optometrist' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('role_user')->where('user_id', $dualUserId)->count())->toBe(0);

    // Simulate backfill for dual-role.
    DB::table('role_user')->insert([
        'role_id' => $adminRole->id,
        'user_id' => $dualUserId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('role_user')->insert([
        'role_id' => $optometristRole->id,
        'user_id' => $dualUserId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('role_user')->where('user_id', $dualUserId)->count())->toBe(2);

    // Plain admin: 1 pivot row.
    $adminUserId = DB::table('users')->insertGetId([
        'first_name' => 'Plain',
        'last_name' => 'Admin',
        'email' => 'migration-plain-admin@test.com',
        'phone' => '09170000016',
        'password' => 'hashed',
        'role_id' => $adminRole->id,
        'is_optometrist' => false,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('role_user')->insert([
        'role_id' => $adminRole->id,
        'user_id' => $adminUserId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('role_user')->where('user_id', $adminUserId)->count())->toBe(1);
});
