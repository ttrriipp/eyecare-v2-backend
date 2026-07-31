<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('full_name');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('full_name')->nullable()->after('user_id');
        });

        // Backfill from structured names
        $patients = DB::table('patients')->select('id', 'first_name', 'middle_name', 'last_name')->get();
        foreach ($patients as $patient) {
            $parts = array_filter([
                $patient->first_name,
                $patient->middle_name,
                $patient->last_name,
            ]);
            DB::table('patients')
                ->where('id', $patient->id)
                ->update(['full_name' => implode(' ', $parts)]);
        }
    }
};
