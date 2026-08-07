<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained('encounters')->nullOnDelete();
            $table->foreignId('optometrist_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('rating')->comment('1-5 star rating');
            $table->text('comment')->nullable();
            $table->json('service_ids')->nullable()->comment('Snapshot of service IDs rendered at this visit');
            $table->foreignId('current_revision_id')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->text('moderation_reason')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('appointment_id');
            $table->index(['patient_id', 'rating']);
            $table->index(['optometrist_id', 'rating']);
        });

        Schema::create('visit_rating_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_rating_id')->constrained('visit_ratings')->cascadeOnDelete();
            $table->unsignedInteger('revision_number')->default(1);
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->foreignId('revised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revised_at');
            $table->timestamps();

            $table->index(['visit_rating_id', 'revision_number']);
        });

        // Add circular FK for current_revision_id
        Schema::table('visit_ratings', function (Blueprint $table): void {
            $table->foreign('current_revision_id')
                ->references('id')
                ->on('visit_rating_revisions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_rating_revisions');
        Schema::dropIfExists('visit_ratings');
    }
};
