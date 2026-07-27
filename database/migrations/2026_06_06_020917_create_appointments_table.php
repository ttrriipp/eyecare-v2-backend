<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->boolean('requires_referral')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_number', 50)->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_type_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->string('referring_source')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('optometrist_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source')->default('manual');
            $table->foreignId('appointment_status_id')->constrained();
            $table->dateTime('scheduled_at');

            // Check-in
            $table->dateTime('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();

            // Fulfillment (replaces completed_at)
            $table->dateTime('fulfilled_at')->nullable();

            // Cancellation metadata
            $table->string('cancelled_by')->nullable(); // 'patient' or 'clinic'
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason_category')->nullable();
            $table->text('cancellation_reason_details')->nullable();
            $table->dateTime('cancelled_at')->nullable();

            // No-show metadata
            $table->foreignId('no_show_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('no_show_at')->nullable();

            // Notes
            $table->text('contact_notes')->nullable();
            $table->text('staff_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('appointment_types');
    }
};
