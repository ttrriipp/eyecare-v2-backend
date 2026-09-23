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
        Schema::table('sms_notifications', function (Blueprint $table): void {
            $table->string('provider_name')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('provider_status', 80)->nullable();
            $table->timestamp('provider_accepted_at')->nullable();
            $table->index(['provider_name', 'provider_reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sms_notifications', function (Blueprint $table): void {
            $table->dropIndex(['provider_name', 'provider_reference']);
            $table->dropColumn([
                'provider_name',
                'provider_reference',
                'provider_status',
                'provider_accepted_at',
            ]);
        });
    }
};
