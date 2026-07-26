<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('frame_ratings', function (Blueprint $table): void {
            $table->boolean('is_hidden')->default(false)->after('comment');
            $table->text('moderation_reason')->nullable()->after('is_hidden');
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete()->after('moderation_reason');
            $table->timestamp('moderated_at')->nullable()->after('moderated_by');
        });
    }

    public function down(): void
    {
        Schema::table('frame_ratings', function (Blueprint $table): void {
            $table->dropColumn(['is_hidden', 'moderation_reason', 'moderated_by', 'moderated_at']);
        });
    }
};
