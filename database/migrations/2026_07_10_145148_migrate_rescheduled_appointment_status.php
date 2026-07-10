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
        $now = now();

        DB::table('appointment_statuses')->insertOrIgnore([
            ['name' => 'pending', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'confirmed', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'arrived', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'completed', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'no_show', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'cancelled', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $pendingStatusId = DB::table('appointment_statuses')->where('name', 'pending')->value('id');
        $rescheduledStatusId = DB::table('appointment_statuses')->where('name', 'rescheduled')->value('id');

        if ($rescheduledStatusId !== null) {
            DB::table('appointments')
                ->where('appointment_status_id', $rescheduledStatusId)
                ->update(['appointment_status_id' => $pendingStatusId]);

            DB::table('appointment_statuses')->where('id', $rescheduledStatusId)->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('appointment_statuses')->insertOrIgnore([
            'name' => 'rescheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
