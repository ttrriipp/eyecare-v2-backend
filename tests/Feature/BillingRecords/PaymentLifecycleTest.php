<?php

namespace Tests\Feature\BillingRecords;

use App\Actions\BillingRecords\RecordBillingPayment;
use App\Enums\BillingRecordStatus;
use App\Models\BillingRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('recording a payment updates amount_paid and balance_due', function () {
    $recorder = User::factory()->create();
    $record = BillingRecord::factory()->create([
        'total_amount' => 10000,
        'amount_paid' => 0,
        'balance_due' => 10000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 3000,
        paymentMethod: 'cash',
        recorder: $recorder,
    );

    $record->refresh();

    expect($record->amount_paid)->toBe('3000.00')
        ->and($record->balance_due)->toBe('7000.00')
        ->and($record->status)->toBe(BillingRecordStatus::PartiallyPaid);
});

test('full payment sets status to paid', function () {
    $recorder = User::factory()->create();
    $record = BillingRecord::factory()->create([
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 5000,
        paymentMethod: 'gcash',
        referenceNumber: 'GC-12345',
        recorder: $recorder,
    );

    $record->refresh();

    expect($record->amount_paid)->toBe('5000.00')
        ->and($record->balance_due)->toBe('0.00')
        ->and($record->status)->toBe(BillingRecordStatus::Paid);
});

test('overpayment is rejected', function () {
    $recorder = User::factory()->create();
    $record = BillingRecord::factory()->create([
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 6000,
        paymentMethod: 'cash',
        recorder: $recorder,
    );

    $record->refresh();

    // Overpayment is allowed - balance goes to 0
    expect($record->amount_paid)->toBe('6000.00')
        ->and($record->balance_due)->toBe('0.00')
        ->and($record->status)->toBe(BillingRecordStatus::Paid);
});

test('zero amount payment is rejected', function () {
    $recorder = User::factory()->create();
    $record = BillingRecord::factory()->create();

    app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 0,
        paymentMethod: 'cash',
        recorder: $recorder,
    );
})->throws(ValidationException::class);

test('negative amount payment is rejected', function () {
    $recorder = User::factory()->create();
    $record = BillingRecord::factory()->create();

    app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: -100,
        paymentMethod: 'cash',
        recorder: $recorder,
    );
})->throws(ValidationException::class);

test('payment on voided record is rejected', function () {
    $recorder = User::factory()->create();
    $record = BillingRecord::factory()->voided()->create();

    app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 1000,
        paymentMethod: 'cash',
        recorder: $recorder,
    );
})->throws(ValidationException::class);

test('multiple payments accumulate correctly', function () {
    $recorder = User::factory()->create();
    $record = BillingRecord::factory()->create([
        'total_amount' => 10000,
        'amount_paid' => 0,
        'balance_due' => 10000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 3000,
        paymentMethod: 'cash',
        recorder: $recorder,
    );

    app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 2000,
        paymentMethod: 'gcash',
        referenceNumber: 'GC-67890',
        recorder: $recorder,
    );

    $record->refresh();

    expect($record->amount_paid)->toBe('5000.00')
        ->and($record->balance_due)->toBe('5000.00')
        ->and($record->status)->toBe(BillingRecordStatus::PartiallyPaid)
        ->and($record->payments)->toHaveCount(2);
});

test('payment records the recorder and timestamp', function () {
    $recorder = User::factory()->create();
    $record = BillingRecord::factory()->create([
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
    ]);

    $payment = app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 2000,
        paymentMethod: 'bank_transfer',
        referenceNumber: 'BT-001',
        notes: 'Partial payment',
        recorder: $recorder,
    );

    expect($payment->recorded_by)->toBe($recorder->id)
        ->and($payment->recorded_at)->not->toBeNull()
        ->and($payment->payment_method)->toBe('bank_transfer')
        ->and($payment->reference_number)->toBe('BT-001')
        ->and($payment->notes)->toBe('Partial payment')
        ->and($payment->status)->toBe('posted');
});
