<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('frame_ratings', function (Blueprint $table): void {
            $table->dropForeign(['current_revision_id']);
            $table->dropColumn('current_revision_id');
        });

        Schema::table('visit_ratings', function (Blueprint $table): void {
            $table->dropForeign(['current_revision_id']);
            $table->dropColumn('current_revision_id');
        });

        Schema::dropIfExists('frame_rating_revisions');
        Schema::dropIfExists('visit_rating_revisions');
    }

    public function down(): void
    {
        Schema::create('frame_rating_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('frame_rating_id')->constrained('frame_ratings')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->foreignId('revised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revised_at')->nullable();
            $table->timestamps();
        });

        Schema::create('visit_rating_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_rating_id')->constrained('visit_ratings')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->foreignId('revised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revised_at')->nullable();
            $table->timestamps();
        });

        Schema::table('frame_ratings', function (Blueprint $table): void {
            $table->foreignId('current_revision_id')->nullable()->after('comment');
        });

        Schema::table('visit_ratings', function (Blueprint $table): void {
            $table->foreignId('current_revision_id')->nullable()->after('comment');
        });
    }
};
