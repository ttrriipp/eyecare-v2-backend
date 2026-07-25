<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->foreignId('patient_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->nullable()->after('appointment_id')->constrained()->nullOnDelete();
            $table->date('expires_at')->nullable()->change();
        });

        // Copy customer_id to patient_id via join on patients.user_id
        DB::statement('
            UPDATE prescriptions
            SET patient_id = (
                SELECT patients.id FROM patients WHERE patients.user_id = prescriptions.customer_id
            )
        ');

        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
            $table->date('expires_at')->nullable(false)->change();
        });

        DB::statement('
            UPDATE prescriptions
            SET customer_id = (
                SELECT patients.user_id FROM patients WHERE patients.id = prescriptions.patient_id
            )
        ');

        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->dropForeign(['patient_id']);
            $table->dropForeign(['encounter_id']);
            $table->dropColumn(['patient_id', 'encounter_id']);
        });
    }
};
