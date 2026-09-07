<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_reschedule_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_number', 50)->unique();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->dateTime('current_scheduled_at');
            $table->dateTime('requested_scheduled_at');
            $table->json('alternative_scheduled_times')->nullable();
            $table->text('encrypted_reason_details')->nullable();
            $table->string('status')->default('pending');
            $table->dateTime('selected_scheduled_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->dateTime('expires_at');
            $table->foreignId('appointment_reschedule_id')->nullable();
            $table->timestamps();

            $table->foreign('appointment_reschedule_id', 'arr_req_reschedule_fk')
                ->references('id')
                ->on('appointment_reschedules')
                ->nullOnDelete();
            $table->unique('appointment_reschedule_id', 'arr_req_reschedule_unique');
            $table->index(['appointment_id', 'status'], 'arr_req_appointment_status_idx');
            $table->index(['user_id', 'status'], 'arr_req_user_status_idx');
            $table->index(['status', 'expires_at'], 'arr_req_status_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_reschedule_requests');
    }
};
