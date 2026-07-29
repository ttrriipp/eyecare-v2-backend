<?php

namespace Tests\Feature\BillingRecords;

use App\Actions\BillingRecords\DispenseJobOrder;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('dispensing creates a billing record and dispensing event', function () {
    $dispenser = User::factory()->create();
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'total_amount' => 5000,
        'supplier_invoice_number' => 'SUP-INV-2001',
    ]);

    $event = app(DispenseJobOrder::class)->handle(
        jobOrder: $jobOrder,
        dispenser: $dispenser,
        recipientName: 'Ana Reyes',
        notes: 'Standard dispensing',
    );

    $jobOrder->refresh();
    $billingRecord = BillingRecord::query()->where('job_order_id', $jobOrder->id)->first();

    expect($jobOrder->status)->toBe(JobOrderStatus::Dispensed)
        ->and($event->dispensed_by)->toBe($dispenser->id)
        ->and($event->recipient_name)->toBe('Ana Reyes')
        ->and($billingRecord)->not->toBeNull()
        ->and($billingRecord->patient_id)->toBe($jobOrder->patient_id)
        ->and($billingRecord->total_amount)->toBe('5000.00')
        ->and($billingRecord->status)->toBe(BillingRecordStatus::Unpaid)
        ->and($billingRecord->billing_record_number)->toStartWith('BR-');
});

test('dispensing with initial payment records payment', function () {
    $dispenser = User::factory()->create();
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'total_amount' => 5000,
        'supplier_invoice_number' => 'SUP-INV-2002',
    ]);

    app(DispenseJobOrder::class)->handle(
        jobOrder: $jobOrder,
        dispenser: $dispenser,
        initialPaymentAmount: 2000,
        initialPaymentMethod: 'gcash',
    );

    $billingRecord = BillingRecord::query()->where('job_order_id', $jobOrder->id)->first();

    expect($billingRecord->amount_paid)->toBe('2000.00')
        ->and($billingRecord->balance_due)->toBe('3000.00')
        ->and($billingRecord->status)->toBe(BillingRecordStatus::PartiallyPaid)
        ->and($billingRecord->payments)->toHaveCount(1);
});

test('dispensing rejects non-ready job orders', function () {
    $dispenser = User::factory()->create();
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);

    app(DispenseJobOrder::class)->handle(
        jobOrder: $jobOrder,
        dispenser: $dispenser,
    );
})->throws(ValidationException::class);

test('dispensing rejects a ready job order without a supplier invoice number', function () {
    $dispenser = User::factory()->create();
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'supplier_invoice_number' => null,
    ]);

    app(DispenseJobOrder::class)->handle(
        jobOrder: $jobOrder,
        dispenser: $dispenser,
    );
})->throws(ValidationException::class, 'Enter the supplier invoice number before marking this job order ready.');

test('dispensing prevents duplicate billing records', function () {
    $dispenser = User::factory()->create();
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'total_amount' => 5000,
        'supplier_invoice_number' => 'SUP-INV-2003',
    ]);

    BillingRecord::factory()->create(['job_order_id' => $jobOrder->id]);

    app(DispenseJobOrder::class)->handle(
        jobOrder: $jobOrder,
        dispenser: $dispenser,
    );
})->throws(ValidationException::class);
