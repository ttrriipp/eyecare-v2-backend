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

        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('appointment_type_id')->nullable()->after('patient_id')->constrained()->nullOnDelete();
            $table->string('referring_source')->nullable()->after('appointment_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign(['appointment_type_id']);
            $table->dropColumn(['appointment_type_id', 'referring_source']);
        });

        Schema::dropIfExists('appointment_types');
    }
};
