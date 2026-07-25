<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encounters', function (Blueprint $table): void {
            $table->id();
            $table->string('encounter_number', 32)->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('patient_intake_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('optometrist_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('waiting');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Optometrist-only findings and remarks (encrypted)
            $table->text('findings')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encounters');
    }
};
