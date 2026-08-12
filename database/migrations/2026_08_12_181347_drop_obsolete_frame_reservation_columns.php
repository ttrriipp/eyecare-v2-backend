<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropForeign(['frame_reservation_id']);
            $table->dropColumn('frame_reservation_id');
        });

        Schema::table('job_orders', function (Blueprint $table): void {
            $table->dropForeign(['frame_reservation_id']);
            $table->dropColumn('frame_reservation_id');
        });

        Schema::table('frame_reservations', function (Blueprint $table): void {
            $table->dropForeign(['released_by']);
            $table->dropColumn([
                'status',
                'expires_at',
                'released_at',
                'released_by',
                'release_reason',
                'deleted_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('frame_reservations', function (Blueprint $table): void {
            $table->string('status', 16)->default('requested')->after('appointment_id');
            $table->timestamp('expires_at')->nullable()->after('staff_notes');
            $table->timestamp('released_at')->nullable()->after('expires_at');
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete()->after('released_at');
            $table->string('release_reason', 50)->nullable()->after('released_by');
            $table->softDeletes();
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->foreignId('frame_reservation_id')->nullable()->constrained('frame_reservations')->nullOnDelete();
        });

        Schema::table('job_orders', function (Blueprint $table): void {
            $table->foreignId('frame_reservation_id')->nullable()->constrained('frame_reservations')->nullOnDelete();
        });
    }
};
