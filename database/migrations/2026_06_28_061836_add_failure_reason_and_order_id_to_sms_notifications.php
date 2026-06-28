<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add new columns and make appointment_id nullable
        Schema::table('sms_notifications', function (Blueprint $table): void {
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete()->after('appointment_id');
            $table->text('failure_reason')->nullable()->after('message');
            $table->foreignId('appointment_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sms_notifications', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
            $table->dropColumn(['order_id', 'failure_reason']);
            $table->foreignId('appointment_id')->nullable(false)->change();
        });
    }
};
