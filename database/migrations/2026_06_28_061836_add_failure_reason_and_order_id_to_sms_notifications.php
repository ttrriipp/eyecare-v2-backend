<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_notifications', function (Blueprint $table): void {
            $table->unsignedBigInteger('order_id')->nullable()->after('appointment_id');
            $table->text('failure_reason')->nullable()->after('message');
            $table->foreignId('appointment_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sms_notifications', function (Blueprint $table): void {
            $table->dropColumn(['order_id', 'failure_reason']);
            $table->foreignId('appointment_id')->nullable(false)->change();
        });
    }
};
