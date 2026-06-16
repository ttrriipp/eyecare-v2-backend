<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop FK columns from conversations (context now lives on individual messages)
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['staff_id']);
            $table->dropForeign(['appointment_id']);
            $table->dropForeign(['order_id']);
            $table->dropColumn(['staff_id', 'appointment_id', 'order_id', 'subject']);
        });

        // Enforce one conversation per customer
        Schema::table('conversations', function (Blueprint $table) {
            $table->unique('customer_id');
        });

        // Per-message context links (polymorphic)
        Schema::create('message_context_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->morphs('contextable');
            $table->timestamps();

            $table->unique(['message_id', 'contextable_type', 'contextable_id'], 'mcl_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_context_links');

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['customer_id']);
            $table->foreignId('staff_id')->nullable()->constrained('users');
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject')->nullable();
        });
    }
};
