<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('appointment_number', 50)->nullable()->unique()->after('id');
        });

        $sequences = [];

        DB::table('appointments')
            ->select(['id', 'created_at'])
            ->orderBy('id')
            ->get()
            ->each(function (object $appointment) use (&$sequences): void {
                $year = Carbon::parse($appointment->created_at ?? now())->format('Y');
                $sequences[$year] = ($sequences[$year] ?? 0) + 1;

                DB::table('appointments')
                    ->where('id', $appointment->id)
                    ->update([
                        'appointment_number' => sprintf('APT-%s-%06d', $year, $sequences[$year]),
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropUnique(['appointment_number']);
            $table->dropColumn('appointment_number');
        });
    }
};
