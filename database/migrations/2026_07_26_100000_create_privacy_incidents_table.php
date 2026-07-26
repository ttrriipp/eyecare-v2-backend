<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_number', 32)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('scope')->nullable();
            $table->string('status', 24)->default('reported');
            $table->foreignId('reported_by')->constrained('users');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('containment_actions')->nullable();
            $table->text('decisions')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('discovered_at')->nullable();
            $table->timestamp('contained_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_incidents');
    }
};
