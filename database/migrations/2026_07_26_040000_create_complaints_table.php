<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
