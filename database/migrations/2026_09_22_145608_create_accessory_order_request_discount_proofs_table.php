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
        Schema::create('accessory_order_request_discount_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('accessory_order_request_id');
            $table->unique('accessory_order_request_id', 'discount_proof_request_unique');
            $table->foreign('accessory_order_request_id', 'discount_proof_request_fk')
                ->references('id')
                ->on('accessory_order_requests')
                ->cascadeOnDelete();
            $table->foreignId('user_id');
            $table->foreign('user_id', 'discount_proof_user_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('file_path');
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size');
            $table->foreignId('reviewed_by')->nullable();
            $table->foreign('reviewed_by', 'discount_proof_reviewer_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accessory_order_request_discount_proofs');
    }
};
