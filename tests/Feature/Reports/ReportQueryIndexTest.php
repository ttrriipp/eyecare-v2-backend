<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('report cohort indexes exist after migrations', function () {
    $expectedIndexes = [
        'billing_records' => 'billing_records_reports_recorded_deleted_status_index',
        'billing_payments' => 'billing_payments_reports_status_recorded_index',
        'job_orders' => [
            'job_orders_reports_created_deleted_status_index',
            'job_orders_reports_cancelled_deleted_status_index',
        ],
        'dispensing_events' => 'dispensing_events_reports_dispensed_job_order_index',
        'visit_ratings' => 'visit_ratings_reports_created_deleted_rating_index',
        'frame_ratings' => 'frame_ratings_reports_created_deleted_rating_index',
    ];

    foreach ($expectedIndexes as $table => $indexes) {
        $actualIndexes = collect(Schema::getIndexes($table))->pluck('name');

        foreach ((array) $indexes as $index) {
            expect($actualIndexes)->toContain($index);
        }
    }
});
