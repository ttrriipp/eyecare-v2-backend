<?php

use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('billing record belongs to a patient', function () {
    $record = BillingRecord::factory()->create();

    expect($record->patient)->toBeInstanceOf(Patient::class)
        ->and($record->patient_id)->toBe($record->patient->id);
});

test('billing record belongs to a job order', function () {
    $record = BillingRecord::factory()->create();

    expect($record->jobOrder)->toBeInstanceOf(JobOrder::class)
        ->and($record->job_order_id)->toBe($record->jobOrder->id);
});

test('billing record has many payments', function () {
    $record = BillingRecord::factory()->create();
    BillingPayment::factory()->count(3)->create(['billing_record_id' => $record->id]);

    expect($record->payments)->toHaveCount(3)
        ->and($record->payments->first())->toBeInstanceOf(BillingPayment::class);
});

test('billing record posted payments exclude reversed', function () {
    $record = BillingRecord::factory()->create();
    BillingPayment::factory()->count(2)->create(['billing_record_id' => $record->id]);
    BillingPayment::factory()->reversed()->create(['billing_record_id' => $record->id]);

    expect($record->payments)->toHaveCount(3)
        ->and($record->postedPayments)->toHaveCount(2);
});

test('job order has one billing record', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Dispensed]);
    BillingRecord::factory()->create(['job_order_id' => $jobOrder->id]);

    expect($jobOrder->billingRecord)->toBeInstanceOf(BillingRecord::class);
});

test('billing record generates a BR number on creation', function () {
    $record = BillingRecord::factory()->create();

    expect($record->billing_record_number)->toStartWith('BR-')
        ->and(strlen($record->billing_record_number))->toBe(14);
});

test('billing record status is cast to enum', function () {
    $record = BillingRecord::factory()->create(['status' => 'unpaid']);

    expect($record->status)->toBe(BillingRecordStatus::Unpaid)
        ->and($record->status->value)->toBe('unpaid');
});

test('billing record amount fields are decimal cast', function () {
    $record = BillingRecord::factory()->create([
        'total_amount' => 1234.56,
        'amount_paid' => 500.00,
        'balance_due' => 734.56,
    ]);

    expect($record->total_amount)->toBe('1234.56')
        ->and($record->amount_paid)->toBe('500.00')
        ->and($record->balance_due)->toBe('734.56');
});
