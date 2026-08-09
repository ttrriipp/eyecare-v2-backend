<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_requests', function (Blueprint $table): void {
            $table->json('alternative_scheduled_times')->nullable()->after('scheduled_at');
            $table->text('encrypted_referring_source')->nullable()->after('encrypted_reason_for_visit');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'alternative_scheduled_times',
                'encrypted_referring_source',
            ]);
        });
    }
};
