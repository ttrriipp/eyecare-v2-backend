<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('category', 100)->unique();
            $table->string('description')->nullable();
            $table->integer('retention_days')->nullable();
            $table->date('next_review_date')->nullable();
            $table->boolean('auto_purge_enabled')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('legal_holds', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_number', 50)->unique();
            $table->string('description');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->date('hold_start_date');
            $table->date('hold_end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_holds');
        Schema::dropIfExists('retention_policies');
    }
};
