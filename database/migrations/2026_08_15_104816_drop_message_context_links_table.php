<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('message_context_links');
    }

    public function down(): void
    {
        Schema::create('message_context_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->morphs('contextable');
            $table->timestamps();

            $table->unique(['message_id', 'contextable_type', 'contextable_id'], 'mcl_unique');
        });
    }
};
