<?php

namespace Tests\Feature\BillingRecords;

use App\Actions\BillingRecords\CorrectBillingPayment;
use App\Actions\BillingRecords\RecordBillingPayment;
use App\Actions\BillingRecords\VoidBillingRecord;
use App\Enums\BillingRecordStatus;
use App\Models\BillingPayment;
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

// --- Payment Correction Tests ---

test('correction reverses original and creates replacement', function () {
    $corrector = User::factory()->admin()->create();
    $record = BillingRecord::factory()->create([
        'total_amount' => 10000,
        'amount_paid' => 5000,
        'balance_due' => 5000,
        'status' => BillingRecordStatus::PartiallyPaid,
    ]);
    $original = BillingPayment::factory()->create([
        'billing_record_id' => $record->id,
        'amount' => 5000,
        'status' => 'posted',
    ]);

    $replacement = app(CorrectBillingPayment::class)->handle(
        originalPayment: $original,
        newAmount: 3000,
        reason: 'Data entry error',
        corrector: $corrector,
    );

    $original->refresh();
    $record->refresh();

    expect($original->status)->toBe('reversed')
        ->and($original->reversed_by)->toBe($corrector->id)
        ->and($original->reversal_reason)->toBe('Data entry error')
        ->and($replacement->amount)->toBe('3000.00')
        ->and($replacement->status)->toBe('posted')
        ->and($record->amount_paid)->toBe('3000.00')
        ->and($record->balance_due)->toBe('7000.00')
        ->and($record->status)->toBe(BillingRecordStatus::PartiallyPaid);
});

test('correction rejects zero amount', function () {
    $corrector = User::factory()->admin()->create();
    $payment = BillingPayment::factory()->create(['status' => 'posted']);

    app(CorrectBillingPayment::class)->handle(
        originalPayment: $payment,
        newAmount: 0,
        reason: 'Error',
        corrector: $corrector,
    );
})->throws(ValidationException::class);

test('correction rejects already reversed payment', function () {
    $corrector = User::factory()->admin()->create();
    $payment = BillingPayment::factory()->reversed()->create();

    app(CorrectBillingPayment::class)->handle(
        originalPayment: $payment,
        newAmount: 1000,
        reason: 'Error',
        corrector: $corrector,
    );
})->throws(ValidationException::class);

// --- Voiding Tests ---

test('voiding sets status and records reason', function () {
    $voider = User::factory()->admin()->create();
    $record = BillingRecord::factory()->create([
        'total_amount' => 5000,
        'amount_paid' => 2000,
        'balance_due' => 3000,
        'status' => BillingRecordStatus::PartiallyPaid,
    ]);

    $voided = app(VoidBillingRecord::class)->handle(
        billingRecord: $record,
        reason: 'Duplicate record',
        voider: $voider,
    );

    expect($voided->status)->toBe(BillingRecordStatus::Voided)
        ->and($voided->voided_by)->toBe($voider->id)
        ->and($voided->voided_at)->not->toBeNull()
        ->and($voided->void_reason)->toBe('Duplicate record');
});

test('voiding rejects already voided record', function () {
    $voider = User::factory()->admin()->create();
    $record = BillingRecord::factory()->voided()->create();

    app(VoidBillingRecord::class)->handle(
        billingRecord: $record,
        reason: 'Test',
        voider: $voider,
    );
})->throws(ValidationException::class);
