<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove development/seeded reservations that have no appointment.
        DB::table('frame_reservations')->whereNull('appointment_id')->delete();

        Schema::table('frame_reservations', function (Blueprint $table): void {
            $table->dropForeign(['appointment_id']);

            $table->foreignId('appointment_id')
                ->nullable(false)
                ->change();

            $table->foreign('appointment_id')
                ->references('id')
                ->on('appointments')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('frame_reservations', function (Blueprint $table): void {
            $table->dropForeign(['appointment_id']);

            $table->foreignId('appointment_id')
                ->nullable()
                ->change();

            $table->foreign('appointment_id')
                ->references('id')
                ->on('appointments')
                ->nullOnDelete();
        });
    }
};
