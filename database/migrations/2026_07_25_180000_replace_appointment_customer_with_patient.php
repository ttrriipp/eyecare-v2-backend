<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('patient_id')->nullable()->after('appointment_number')->constrained()->nullOnDelete();
        });

        // Copy customer_id to patient_id via join on patients.user_id
        DB::statement('
            UPDATE appointments
            SET patient_id = (
                SELECT patients.id FROM patients WHERE patients.user_id = appointments.customer_id
            )
        ');

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('appointment_number')->constrained('users')->nullOnDelete();
        });

        DB::statement('
            UPDATE appointments
            SET customer_id = (
                SELECT patients.user_id FROM patients WHERE patients.id = appointments.patient_id
            )
        ');

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign(['patient_id']);
            $table->dropColumn('patient_id');
        });
    }
};
