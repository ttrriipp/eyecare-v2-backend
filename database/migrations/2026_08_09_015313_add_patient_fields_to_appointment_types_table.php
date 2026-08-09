<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_types', function (Blueprint $table): void {
            $table->string('patient_label')->nullable()->after('name');
            $table->text('patient_description')->nullable()->after('patient_label');
            $table->boolean('is_patient_visible')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_types', function (Blueprint $table): void {
            $table->dropColumn(['patient_label', 'patient_description', 'is_patient_visible']);
        });
    }
};
