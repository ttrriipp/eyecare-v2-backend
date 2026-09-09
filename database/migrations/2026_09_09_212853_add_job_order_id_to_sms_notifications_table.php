<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_notifications', function (Blueprint $table): void {
            $table->foreignId('job_order_id')
                ->nullable()
                ->after('appointment_id')
                ->constrained('job_orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sms_notifications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('job_order_id');
        });
    }
};
