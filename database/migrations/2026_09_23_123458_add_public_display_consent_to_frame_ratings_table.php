<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('frame_ratings', function (Blueprint $table): void {
            $table->timestamp('public_display_consent_at')->nullable()->after('comment');
            $table->index(
                ['product_variant_id', 'public_display_consent_at', 'is_hidden', 'created_at'],
                'frame_ratings_public_reviews_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('frame_ratings', function (Blueprint $table): void {
            $table->dropIndex('frame_ratings_public_reviews_index');
            $table->dropColumn('public_display_consent_at');
        });
    }
};
