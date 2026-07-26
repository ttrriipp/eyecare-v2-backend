<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frame_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dispensing_event_id')->nullable()->constrained('dispensing_events')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->foreignId('current_revision_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // One current rating per patient per dispensed frame
            $table->unique(['patient_id', 'product_variant_id']);
        });

        Schema::create('frame_rating_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('frame_rating_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision_number')->default(1);
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->foreignId('revised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revised_at')->nullable();
            $table->timestamps();
        });

        Schema::table('frame_ratings', function (Blueprint $table): void {
            $table->foreign('current_revision_id')->references('id')->on('frame_rating_revisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frame_rating_revisions');
        Schema::dropIfExists('frame_ratings');
    }
};
