<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_requests', function (Blueprint $table): void {
            $table->string('request_type')->default('new')->after('request_number');
            $table->timestamp('original_scheduled_at')->nullable()->after('appointment_id');
            $table->timestamp('selected_scheduled_at')->nullable()->after('original_scheduled_at');
            $table->text('encrypted_reason_for_visit')->nullable()->change();
            $table->index(
                ['appointment_id', 'status', 'expires_at'],
                'appointment_requests_appointment_id_status_expires_at_index',
            );
            $table->dropUnique('appointment_requests_appointment_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_requests', function (Blueprint $table): void {
            $table->dropIndex('appointment_requests_appointment_id_status_expires_at_index');
            $table->dropColumn([
                'request_type',
                'original_scheduled_at',
                'selected_scheduled_at',
            ]);
            $table->text('encrypted_reason_for_visit')->nullable(false)->change();
            $table->unique('appointment_id');
        });
    }
};
