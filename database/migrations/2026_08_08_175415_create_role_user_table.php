<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'user_id']);
        });

        // Ensure the optometrist role exists.
        $now = now();
        DB::table('roles')
            ->where('name', 'optometrist')
            ->first() ?? DB::table('roles')->insert([
                'name' => 'optometrist',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        // Gather role IDs.
        $roleIds = DB::table('roles')
            ->whereIn('name', ['admin', 'staff', 'patient', 'optometrist'])
            ->pluck('id', 'name');

        $knownRoleIds = $roleIds->only(['admin', 'staff', 'patient'])->values()->all();

        // Validate that every user has a known legacy role before modifying anything.
        $unknownUsers = DB::table('users')
            ->whereNotIn('role_id', $knownRoleIds)
            ->count();

        if ($unknownUsers > 0) {
            throw new RuntimeException(
                "Found {$unknownUsers} users with unknown role_id values. ".
                'Resolve them before running this migration.',
            );
        }

        // Backfill assignments in a transaction.
        DB::transaction(function () use ($roleIds): void {
            $now = now();

            // Patient users → patient role only.
            $patientUsers = DB::table('users')
                ->where('role_id', $roleIds->get('patient'))
                ->pluck('id');

            foreach ($patientUsers as $userId) {
                DB::table('role_user')->insert([
                    'role_id' => $roleIds->get('patient'),
                    'user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Staff users without optometrist flag → staff role only.
            $staffUsers = DB::table('users')
                ->where('role_id', $roleIds->get('staff'))
                ->where('is_optometrist', false)
                ->pluck('id');

            foreach ($staffUsers as $userId) {
                DB::table('role_user')->insert([
                    'role_id' => $roleIds->get('staff'),
                    'user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Staff users with optometrist flag → optometrist role only.
            $staffOptometrists = DB::table('users')
                ->where('role_id', $roleIds->get('staff'))
                ->where('is_optometrist', true)
                ->pluck('id');

            foreach ($staffOptometrists as $userId) {
                DB::table('role_user')->insert([
                    'role_id' => $roleIds->get('optometrist'),
                    'user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Admin users without optometrist flag → admin role only.
            $adminUsers = DB::table('users')
                ->where('role_id', $roleIds->get('admin'))
                ->where('is_optometrist', false)
                ->pluck('id');

            foreach ($adminUsers as $userId) {
                DB::table('role_user')->insert([
                    'role_id' => $roleIds->get('admin'),
                    'user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Admin users with optometrist flag → admin + optometrist roles.
            $adminOptometrists = DB::table('users')
                ->where('role_id', $roleIds->get('admin'))
                ->where('is_optometrist', true)
                ->pluck('id');

            foreach ($adminOptometrists as $userId) {
                DB::table('role_user')->insert([
                    'role_id' => $roleIds->get('admin'),
                    'user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('role_user')->insert([
                    'role_id' => $roleIds->get('optometrist'),
                    'user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_user');

        // Remove the optometrist role if it exists.
        DB::table('roles')->where('name', 'optometrist')->delete();
    }
};
