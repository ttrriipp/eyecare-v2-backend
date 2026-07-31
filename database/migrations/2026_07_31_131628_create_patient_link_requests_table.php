<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_link_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number')->unique(); // PLR-YYYY-NNNNNN
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('encrypted_identity_snapshot')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected, expired
            $table->foreignId('reviewed_patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_link_requests');
    }
};
