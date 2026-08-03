<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('users')
            ->select(['id', 'name', 'first_name', 'middle_name', 'last_name'])
            ->whereNotNull('name')
            ->orderBy('id')
            ->get()
            ->each(function (object $user): void {
                $nameParts = preg_split('/\s+/', trim((string) $user->name)) ?: [];
                $honorifics = ['dr', 'dr.', 'mr', 'mr.', 'mrs', 'mrs.', 'ms', 'ms.', 'prof', 'prof.'];

                if (isset($nameParts[0]) && in_array(strtolower($nameParts[0]), $honorifics, true)) {
                    array_shift($nameParts);
                }

                $firstName = trim((string) (array_shift($nameParts) ?? ''));
                $lastName = trim(implode(' ', $nameParts));
                $updates = [];

                if (blank($user->first_name) && $firstName !== '') {
                    $updates['first_name'] = $firstName;
                }

                if (blank($user->last_name) && $lastName !== '') {
                    $updates['last_name'] = $lastName;
                }

                if ($updates !== []) {
                    DB::table('users')->where('id', $user->id)->update($updates);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The legacy column is restored by the following migration rollback.
    }
};
