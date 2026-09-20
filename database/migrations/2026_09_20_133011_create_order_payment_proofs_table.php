<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payment_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('file_path');
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size');
            $table->string('sender_name', 100);
            $table->string('reference_number', 100);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payment_proofs');
    }
};
