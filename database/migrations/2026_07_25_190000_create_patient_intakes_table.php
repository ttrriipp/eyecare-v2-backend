<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_intakes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('draft');

            // Appointment type snapshot
            $table->string('appointment_type')->nullable();

            // Patient demographic snapshot
            $table->string('full_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('occupation')->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();

            // Clinical narrative (encrypted)
            $table->text('chief_complaint')->nullable();
            $table->text('past_ocular_history')->nullable();
            $table->text('past_surgical_history')->nullable();
            $table->text('past_medical_history')->nullable();
            $table->text('allergies')->nullable();
            $table->text('medications')->nullable();

            // Submission and verification
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_intakes');
    }
};
