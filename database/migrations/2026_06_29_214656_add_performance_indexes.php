<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->index('scheduled_at');
        });

        Schema::table('billings', function (Blueprint $table) {
            $table->index('issued_at');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('sms_notifications', function (Blueprint $table) {
            $table->index(['notification_status_id', 'created_at']);
        });

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $table) => $table->dropIndex(['scheduled_at']));
        Schema::table('billings', fn (Blueprint $table) => $table->dropIndex(['issued_at']));
        Schema::table('payments', fn (Blueprint $table) => $table->dropIndex(['created_at']));
        Schema::table('sms_notifications', fn (Blueprint $table) => $table->dropIndex(['notification_status_id', 'created_at']));
        Schema::table('prescriptions', fn (Blueprint $table) => $table->dropIndex(['expires_at']));
    }
};
