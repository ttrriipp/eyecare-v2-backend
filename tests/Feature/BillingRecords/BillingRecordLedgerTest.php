<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('billing_records table has the approved columns', function () {
    expect(Schema::hasTable('billing_records'))->toBeTrue()
        ->and(Schema::hasColumns('billing_records', [
            'id',
            'billing_record_number',
            'patient_id',
            'job_order_id',
            'encounter_id',
            'status',
            'total_amount',
            'amount_paid',
            'balance_due',
            'notes',
            'recorded_by',
            'recorded_at',
            'voided_by',
            'voided_at',
            'void_reason',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

test('billing_records job_order_id is unique', function () {
    $indexes = DB::select("SHOW INDEX FROM billing_records WHERE Column_name = 'job_order_id'");
    $uniqueIndexes = array_filter($indexes, fn ($index) => $index->Non_unique === 0);

    expect(count($uniqueIndexes))->toBeGreaterThan(0);
});

test('billing_records status default is unpaid', function () {
    $columns = DB::select("SHOW COLUMNS FROM billing_records WHERE Field = 'status'");
    $statusColumn = $columns[0];

    expect($statusColumn->Default)->toBe('unpaid');
});

test('billing_payments table has the approved columns', function () {
    expect(Schema::hasTable('billing_payments'))->toBeTrue()
        ->and(Schema::hasColumns('billing_payments', [
            'id',
            'billing_record_id',
            'amount',
            'payment_method',
            'reference_number',
            'status',
            'recorded_by',
            'recorded_at',
            'notes',
            'reversed_by',
            'reversed_at',
            'reversal_reason',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

test('billing_payments status default is posted', function () {
    $columns = DB::select("SHOW COLUMNS FROM billing_payments WHERE Field = 'status'");
    $statusColumn = $columns[0];

    expect($statusColumn->Default)->toBe('posted');
});

test('invoice_items table does not exist', function () {
    expect(Schema::hasTable('invoice_items'))->toBeFalse();
});

test('invoices table does not exist', function () {
    expect(Schema::hasTable('invoices'))->toBeFalse();
});

test('invoice_payments table does not exist', function () {
    expect(Schema::hasTable('invoice_payments'))->toBeFalse();
});

test('billing record status supports only approved values', function () {
    $statuses = ['unpaid', 'partially_paid', 'paid', 'voided'];

    foreach ($statuses as $status) {
        $record = \App\Models\BillingRecord::factory()->create(['status' => $status]);
        expect($record->status->value)->toBe($status);
    }
});
