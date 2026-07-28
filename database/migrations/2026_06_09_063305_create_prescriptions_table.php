<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained();
            $table->foreignId('encounter_id')->nullable();
            $table->foreignId('previous_prescription_id')->nullable()->constrained('prescriptions');
            $table->foreignId('created_by')->constrained('users');
            $table->text('od_sphere')->nullable();
            $table->text('od_cylinder')->nullable();
            $table->text('od_axis')->nullable();
            $table->text('od_add')->nullable();
            $table->text('os_sphere')->nullable();
            $table->text('os_cylinder')->nullable();
            $table->text('os_axis')->nullable();
            $table->text('os_add')->nullable();
            $table->text('pd')->nullable();
            $table->date('prescribed_at');
            $table->date('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};
