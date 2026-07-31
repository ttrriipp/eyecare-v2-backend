<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_link_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_request_id')->constrained('patient_link_requests')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('match_strength'); // strong, moderate, weak
            $table->json('reason_codes')->nullable();
            $table->unsignedSmallInteger('rank')->default(0);
            $table->timestamps();

            $table->unique(['link_request_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_link_candidates');
    }
};
