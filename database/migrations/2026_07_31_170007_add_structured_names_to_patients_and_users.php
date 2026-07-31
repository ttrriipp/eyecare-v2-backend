<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add middle_name to users
        Schema::table('users', function (Blueprint $table) {
            $table->string('middle_name')->nullable()->after('last_name');
        });

        // Add structured names to patients
        Schema::table('patients', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('full_name');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');
        });

        // Backfill: split existing full_name into first/last for patients
        // This is a best-effort split for existing data
        $patients = DB::table('patients')->select('id', 'full_name')->get();
        foreach ($patients as $patient) {
            $parts = explode(' ', trim($patient->full_name), 2);
            $firstName = $parts[0] ?? '';
            $lastName = $parts[1] ?? '';

            DB::table('patients')
                ->where('id', $patient->id)
                ->update([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('middle_name');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'middle_name', 'last_name']);
        });
    }
};
