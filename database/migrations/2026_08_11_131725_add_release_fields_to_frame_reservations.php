<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('frame_reservations', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('expires_at');
            $table->foreignId('released_by')->nullable()->after('released_at')->constrained('users')->nullOnDelete();
            $table->string('release_reason', 50)->nullable()->after('released_by');
        });
    }

    public function down(): void
    {
        Schema::table('frame_reservations', function (Blueprint $table) {
            $table->dropForeign(['released_by']);
            $table->dropColumn(['released_at', 'released_by', 'release_reason']);
        });
    }
};
