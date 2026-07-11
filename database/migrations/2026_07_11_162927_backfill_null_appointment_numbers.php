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
        $sequences = [];

        DB::table('appointments')
            ->whereNotNull('appointment_number')
            ->select(['appointment_number'])
            ->orderBy('id')
            ->get()
            ->each(function (object $appointment) use (&$sequences): void {
                if (preg_match('/^APT-(\d{4})-(\d{6})$/', (string) $appointment->appointment_number, $matches) !== 1) {
                    return;
                }

                $year = $matches[1];
                $sequences[$year] = max($sequences[$year] ?? 0, (int) $matches[2]);
            });

        DB::table('appointments')
            ->whereNull('appointment_number')
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

        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('appointment_number', 50)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('appointment_number', 50)->nullable()->change();
        });
    }
};
