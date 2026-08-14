<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->dropForeign(['patient_intake_id']);
            $table->dropColumn('patient_intake_id');
        });

        Schema::dropIfExists('patient_intakes');
        Schema::dropIfExists('complaints');
    }

    public function down(): void
    {
        Schema::create('complaints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('original_job_order_id')->nullable()->constrained('job_orders')->nullOnDelete();
            $table->foreignId('original_dispensing_event_id')->nullable()->constrained('dispensing_events')->nullOnDelete();
            $table->string('status', 16)->default('open');
            $table->text('patient_description')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('new_appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignId('new_encounter_id')->nullable()->constrained('encounters')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('complaint_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('patient_intakes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('draft');
            $table->string('appointment_type')->nullable();
            $table->string('full_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('occupation')->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('chief_complaint')->nullable();
            $table->text('past_ocular_history')->nullable();
            $table->text('past_surgical_history')->nullable();
            $table->text('past_medical_history')->nullable();
            $table->text('allergies')->nullable();
            $table->text('medications')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('encounters', function (Blueprint $table): void {
            $table->foreignId('patient_intake_id')->nullable()->constrained()->nullOnDelete();
        });
    }
};
