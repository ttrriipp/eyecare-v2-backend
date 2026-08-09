<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encounter_addenda', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('encounter_id')
                ->constrained('encounters')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('sequence_number');
            $table->string('type', 20); // correction or supplement
            $table->text('reason'); // encrypted
            $table->text('content'); // encrypted
            $table->foreignId('authored_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('authored_at');
            $table->timestamps();

            $table->unique(['encounter_id', 'sequence_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encounter_addenda');
    }
};
