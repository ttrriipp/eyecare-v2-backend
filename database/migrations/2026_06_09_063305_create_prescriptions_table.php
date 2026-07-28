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

            // Main measurement group
            $table->text('main_od_value')->nullable();
            $table->text('main_od_sphere')->nullable();
            $table->text('main_od_cylinder')->nullable();
            $table->text('main_os_value')->nullable();
            $table->text('main_os_sphere')->nullable();
            $table->text('main_os_cylinder')->nullable();

            // ADD measurement group
            $table->text('add_od_value')->nullable();
            $table->text('add_od_sphere')->nullable();
            $table->text('add_od_cylinder')->nullable();
            $table->text('add_os_value')->nullable();
            $table->text('add_os_sphere')->nullable();
            $table->text('add_os_cylinder')->nullable();

            $table->text('remarks')->nullable();
            $table->text('amendment_reason')->nullable();
            $table->date('prescribed_at');
            $table->timestamps();
            $table->softDeletes();
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
