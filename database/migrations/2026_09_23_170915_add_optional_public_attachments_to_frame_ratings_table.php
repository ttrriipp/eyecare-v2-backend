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
            $table->text('attachment_path')->nullable()->after('comment');
            $table->uuid('attachment_public_id')->nullable()->unique()->after('attachment_path');
            $table->string('attachment_mime_type', 64)->nullable()->after('attachment_public_id');
            $table->timestamp('public_attachment_consent_at')->nullable()->after('attachment_mime_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('frame_ratings', function (Blueprint $table): void {
            $table->dropUnique(['attachment_public_id']);
            $table->dropColumn([
                'attachment_path',
                'attachment_public_id',
                'attachment_mime_type',
                'public_attachment_consent_at',
            ]);
        });
    }
};
