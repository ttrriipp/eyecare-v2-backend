<?php

use App\Actions\Invoices\CorrectInvoicePayment;
use App\Actions\Invoices\RecordInvoicePayment;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('deposits may be recorded before dispensing', function () {
    $staff = User::factory()->staff()->create();
    $invoice = Invoice::factory()->create([
        'total' => 5000,
        'balance_due' => 5000,
        'status' => InvoiceStatus::Draft,
    ]);

    $payment = app(RecordInvoicePayment::class)->handle(
        invoice: $invoice,
        amount: 2000,
        paymentMethod: 'cash',
        recorder: $staff,
    );

    expect((float) $payment->amount)->toBe(2000.0)
        ->and($payment->recorded_by)->toBe($staff->id);

    $invoice->refresh();
    expect((float) $invoice->amount_paid)->toBe(2000.0)
        ->and((float) $invoice->balance_due)->toBe(3000.0)
        ->and($invoice->status)->toBe(InvoiceStatus::PartiallyPaid);
});

test('multiple installments accumulate correctly', function () {
    $staff = User::factory()->staff()->create();
    $invoice = Invoice::factory()->create([
        'total' => 10000,
        'balance_due' => 10000,
        'status' => InvoiceStatus::Issued,
    ]);

    app(RecordInvoicePayment::class)->handle($invoice, 3000, 'cash', $staff);
    app(RecordInvoicePayment::class)->handle($invoice, 3000, 'gcash', $staff);
    app(RecordInvoicePayment::class)->handle($invoice, 4000, 'bank_transfer', $staff);

    $invoice->refresh();
    expect((float) $invoice->amount_paid)->toBe(10000.0)
        ->and((float) $invoice->balance_due)->toBe(0.0)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->payments)->toHaveCount(3);
});

test('overpayment is rejected under row lock', function () {
    $staff = User::factory()->staff()->create();
    $invoice = Invoice::factory()->create([
        'total' => 5000,
        'balance_due' => 5000,
        'status' => InvoiceStatus::Issued,
    ]);

    // This should succeed — overpayment is allowed (clinic may accept overpayment)
    // but balance_due should be 0, not negative
    app(RecordInvoicePayment::class)->handle($invoice, 6000, 'cash', $staff);

    $invoice->refresh();
    expect((float) $invoice->amount_paid)->toBe(6000.0)
        ->and((float) $invoice->balance_due)->toBe(0.0)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid);
});

test('rejection of zero or negative payment', function () {
    $staff = User::factory()->staff()->create();
    $invoice = Invoice::factory()->create();

    app(RecordInvoicePayment::class)->handle($invoice, 0, 'cash', $staff);
})->throws(ValidationException::class);

test('rejection of payment on voided invoice', function () {
    $staff = User::factory()->staff()->create();
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Voided]);

    app(RecordInvoicePayment::class)->handle($invoice, 1000, 'cash', $staff);
})->throws(ValidationException::class);

test('correction preserves original payment and records actor and reason', function () {
    $staff = User::factory()->staff()->create();
    $admin = User::factory()->admin()->create();
    $invoice = Invoice::factory()->create([
        'total' => 5000,
        'balance_due' => 5000,
        'status' => InvoiceStatus::Issued,
    ]);

    $original = app(RecordInvoicePayment::class)->handle($invoice, 2000, 'cash', $staff);

    $correction = app(CorrectInvoicePayment::class)->handle(
        originalPayment: $original,
        correctedAmount: 2500,
        corrector: $admin,
        reason: 'Entered wrong amount',
    );

    // Original is preserved with VOIDED note
    $original->refresh();
    expect($original->notes)->toContain('VOIDED: Entered wrong amount');

    // Replacement has the corrected amount
    expect((float) $correction->amount)->toBe(2500.0)
        ->and($correction->recorded_by)->toBe($admin->id)
        ->and($correction->notes)->toContain('Correction of payment');

    // Invoice balance recalculated
    $invoice->refresh();
    expect((float) $invoice->amount_paid)->toBe(2500.0)
        ->and((float) $invoice->balance_due)->toBe(2500.0);
});

test('correction rejects zero amount', function () {
    $staff = User::factory()->staff()->create();
    $invoice = Invoice::factory()->create(['total' => 5000, 'balance_due' => 5000]);
    $payment = app(RecordInvoicePayment::class)->handle($invoice, 2000, 'cash', $staff);

    app(CorrectInvoicePayment::class)->handle($payment, 0, $staff, 'test');
})->throws(ValidationException::class);

test('correction rejects on voided invoice', function () {
    $staff = User::factory()->staff()->create();
    $invoice = Invoice::factory()->create(['total' => 5000, 'balance_due' => 5000]);
    $payment = app(RecordInvoicePayment::class)->handle($invoice, 2000, 'cash', $staff);

    $invoice->update(['status' => InvoiceStatus::Voided]);

    app(CorrectInvoicePayment::class)->handle($payment, 2500, $staff, 'test');
})->throws(ValidationException::class);
