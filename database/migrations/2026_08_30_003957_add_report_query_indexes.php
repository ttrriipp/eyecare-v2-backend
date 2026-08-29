<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_records', function (Blueprint $table): void {
            $table->index(
                ['recorded_at', 'deleted_at', 'status'],
                'billing_records_reports_recorded_deleted_status_index',
            );
        });

        Schema::table('billing_payments', function (Blueprint $table): void {
            $table->index(
                ['status', 'recorded_at'],
                'billing_payments_reports_status_recorded_index',
            );
        });

        Schema::table('job_orders', function (Blueprint $table): void {
            $table->index(
                ['created_at', 'deleted_at', 'status'],
                'job_orders_reports_created_deleted_status_index',
            );
            $table->index(
                ['cancelled_at', 'deleted_at', 'status'],
                'job_orders_reports_cancelled_deleted_status_index',
            );
        });

        Schema::table('dispensing_events', function (Blueprint $table): void {
            $table->index(
                ['dispensed_at', 'job_order_id'],
                'dispensing_events_reports_dispensed_job_order_index',
            );
        });

        Schema::table('visit_ratings', function (Blueprint $table): void {
            $table->index(
                ['created_at', 'deleted_at', 'rating'],
                'visit_ratings_reports_created_deleted_rating_index',
            );
        });

        Schema::table('frame_ratings', function (Blueprint $table): void {
            $table->index(
                ['created_at', 'deleted_at', 'rating'],
                'frame_ratings_reports_created_deleted_rating_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('frame_ratings', function (Blueprint $table): void {
            $table->dropIndex('frame_ratings_reports_created_deleted_rating_index');
        });

        Schema::table('visit_ratings', function (Blueprint $table): void {
            $table->dropIndex('visit_ratings_reports_created_deleted_rating_index');
        });

        Schema::table('dispensing_events', function (Blueprint $table): void {
            $table->dropIndex('dispensing_events_reports_dispensed_job_order_index');
        });

        Schema::table('job_orders', function (Blueprint $table): void {
            $table->dropIndex('job_orders_reports_created_deleted_status_index');
            $table->dropIndex('job_orders_reports_cancelled_deleted_status_index');
        });

        Schema::table('billing_payments', function (Blueprint $table): void {
            $table->dropIndex('billing_payments_reports_status_recorded_index');
        });

        Schema::table('billing_records', function (Blueprint $table): void {
            $table->dropIndex('billing_records_reports_recorded_deleted_status_index');
        });
    }
};
