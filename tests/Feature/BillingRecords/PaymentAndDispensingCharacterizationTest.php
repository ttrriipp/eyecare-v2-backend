<?php

/**
 * Characterization tests for payment and dispensing behavior.
 *
 * Protects deposits, partial payments, corrections, charge-set locking,
 * and valid Ready-to-Completed behavior before tightening invariants.
 *
 * @see tasks/todo.md Task 2
 */

use App\Actions\BillingRecords\CorrectBillingPayment;
use App\Actions\BillingRecords\DispenseJobOrder;
use App\Actions\BillingRecords\RecordBillingPayment;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\DispensingEvent;
use App\Models\JobOrder;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->actingAs($this->staff);
});

// ─── Deposits and partial payments ───────────────────────────────────────────

test('valid deposit updates ledger correctly', function () {
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
        recorder: $this->staff,
        chargesReviewed: true,
    );

    $record->refresh();

    expect((float) $record->amount_paid)->toBe(3000.0)
        ->and((float) $record->balance_due)->toBe(7000.0)
        ->and($record->status)->toBe(BillingRecordStatus::PartiallyPaid);
});

test('later partial payment accumulates correctly', function () {
    $record = BillingRecord::factory()->create([
        'total_amount' => 10000,
        'amount_paid' => 3000,
        'balance_due' => 7000,
        'status' => BillingRecordStatus::PartiallyPaid,
    ]);

    app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 2000,
        paymentMethod: 'gcash',
        recorder: $this->staff,
        chargesReviewed: true,
    );

    $record->refresh();

    expect((float) $record->amount_paid)->toBe(5000.0)
        ->and((float) $record->balance_due)->toBe(5000.0)
        ->and($record->status)->toBe(BillingRecordStatus::PartiallyPaid);
});

test('exact-balance payment sets status to paid', function () {
    $record = BillingRecord::factory()->create([
        'total_amount' => 5000,
        'amount_paid' => 3000,
        'balance_due' => 2000,
        'status' => BillingRecordStatus::PartiallyPaid,
    ]);

    app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 2000,
        paymentMethod: 'cash',
        recorder: $this->staff,
        chargesReviewed: true,
    );

    $record->refresh();

    expect((float) $record->amount_paid)->toBe(5000.0)
        ->and((float) $record->balance_due)->toBe(0.0)
        ->and($record->status)->toBe(BillingRecordStatus::Paid);
});

// ─── Overpayment characterization ────────────────────────────────────────────

test('overpayment currently clamps balance to zero instead of rejecting', function () {
    // CHARACTERIZATION: This captures the current behavior where overpayment
    // is allowed and balance clamps to zero. The spec requires this to be
    // rejected (Task 25).
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
        recorder: $this->staff,
        chargesReviewed: true,
    );

    $record->refresh();

    // Current: overpayment is allowed, balance clamps to 0
    expect((float) $record->amount_paid)->toBe(6000.0)
        ->and((float) $record->balance_due)->toBe(0.0)
        ->and($record->status)->toBe(BillingRecordStatus::Paid);
});

// ─── First payment charge locking ────────────────────────────────────────────

test('first payment without charge review is rejected', function () {
    $record = BillingRecord::factory()->create([
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 2000,
        paymentMethod: 'cash',
        recorder: $this->staff,
        chargesReviewed: false,
    );
})->throws(ValidationException::class, 'Recording this payment will finalize the charges');

test('first payment with charge review succeeds', function () {
    $record = BillingRecord::factory()->create([
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    $payment = app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 2000,
        paymentMethod: 'cash',
        recorder: $this->staff,
        chargesReviewed: true,
    );

    expect($payment->status)->toBe('posted')
        ->and($payment->amount)->toBe('2000.00');
});

test('subsequent payment does not require charge review', function () {
    $record = BillingRecord::factory()->create([
        'total_amount' => 5000,
        'amount_paid' => 2000,
        'balance_due' => 3000,
        'status' => BillingRecordStatus::PartiallyPaid,
    ]);

    // Seed an existing posted payment so hasPostedPayments is true
    BillingPayment::factory()->create([
        'billing_record_id' => $record->id,
        'amount' => 2000,
        'status' => 'posted',
    ]);

    $payment = app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 1000,
        paymentMethod: 'cash',
        recorder: $this->staff,
        chargesReviewed: false, // Not required after first payment
    );

    expect($payment->status)->toBe('posted');
});

// ─── Payment correction ─────────────────────────────────────────────────────

test('correction is append-only: reverses original and creates replacement', function () {
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

    // Original is reversed, not deleted
    expect($original->status)->toBe('reversed')
        ->and($original->reversed_by)->toBe($corrector->id)
        ->and($original->reversal_reason)->toBe('Data entry error');

    // Replacement has correct amount
    expect($replacement->amount)->toBe('3000.00')
        ->and($replacement->status)->toBe('posted');

    // Ledger updated
    expect((float) $record->amount_paid)->toBe(3000.0)
        ->and((float) $record->balance_due)->toBe(7000.0);
});

test('already-reversed payment cannot be corrected again', function () {
    $corrector = User::factory()->admin()->create();
    $payment = BillingPayment::factory()->reversed()->create();

    app(CorrectBillingPayment::class)->handle(
        originalPayment: $payment,
        newAmount: 1000,
        reason: 'Error',
        corrector: $corrector,
    );
})->throws(ValidationException::class);

// ─── Dispensing ──────────────────────────────────────────────────────────────

test('successful dispensing records exactly one dispensing event', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'total_amount' => 5000,
        'supplier_invoice_number' => 'INV-001',
    ]);

    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 5000,
        'amount_paid' => 5000,
        'balance_due' => 0,
        'status' => BillingRecordStatus::Paid,
    ]);

    $event = app(DispenseJobOrder::class)->handle(
        $jobOrder,
        $this->staff,
        recipientName: 'Patient Name',
        notes: 'Picked up',
    );

    expect($event)->toBeInstanceOf(DispensingEvent::class)
        ->and($event->job_order_id)->toBe($jobOrder->id)
        ->and($event->dispensed_by)->toBe($this->staff->id)
        ->and($event->recipient_name)->toBe('Patient Name');

    // Exactly one event
    expect(DispensingEvent::where('job_order_id', $jobOrder->id)->count())->toBe(1);

    // Job order is dispensed
    expect($jobOrder->fresh()->status)->toBe(JobOrderStatus::Dispensed);
});

test('dispensing currently allows nonzero balance', function () {
    // CHARACTERIZATION: This captures the current behavior where dispensing
    // proceeds even with a remaining balance. The spec requires this to be
    // gated (Task 28).
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'total_amount' => 5000,
        'supplier_invoice_number' => 'INV-002',
    ]);

    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    $event = app(DispenseJobOrder::class)->handle(
        $jobOrder,
        $this->staff,
    );

    // Currently succeeds even with unpaid balance
    expect($event)->toBeInstanceOf(DispensingEvent::class)
        ->and($jobOrder->fresh()->status)->toBe(JobOrderStatus::Dispensed);
});

test('dispensing with pickup payment updates billing atomically', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'total_amount' => 10000,
        'supplier_invoice_number' => 'INV-003',
    ]);

    $billingRecord = BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 10000,
        'amount_paid' => 3000,
        'balance_due' => 7000,
        'status' => BillingRecordStatus::PartiallyPaid,
    ]);

    app(DispenseJobOrder::class)->handle(
        $jobOrder,
        $this->staff,
        pickupPaymentAmount: 7000,
        pickupPaymentMethod: 'gcash',
    );

    $billingRecord = $billingRecord->refresh();

    expect((float) $billingRecord->amount_paid)->toBe(10000.0)
        ->and((float) $billingRecord->balance_due)->toBe(0.0)
        ->and($billingRecord->status)->toBe(BillingRecordStatus::Paid);
});

test('dispensing against voided billing is rejected', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'supplier_invoice_number' => 'INV-004',
    ]);

    BillingRecord::factory()->voided()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    app(DispenseJobOrder::class)->handle($jobOrder, $this->staff);
})->throws(ValidationException::class, 'No billing record found');

test('only ready-for-dispensing orders can be dispensed', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Queued,
        'supplier_invoice_number' => 'INV-005',
    ]);

    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    app(DispenseJobOrder::class)->handle($jobOrder, $this->staff);
})->throws(ValidationException::class);
