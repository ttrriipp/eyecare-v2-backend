<?php

/**
 * Tests for payment overage rejection under lock.
 *
 * @see tasks/todo.md Task 25
 */

use App\Actions\BillingRecords\RecordBillingPayment;
use App\Enums\BillingRecordStatus;
use App\Models\BillingRecord;
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

test('zero amount payment is rejected', function () {
    $record = BillingRecord::factory()->create([
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 0,
        paymentMethod: 'cash',
        recorder: $this->staff,
        chargesReviewed: true,
    );
})->throws(ValidationException::class);

test('negative amount payment is rejected', function () {
    $record = BillingRecord::factory()->create([
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: -100,
        paymentMethod: 'cash',
        recorder: $this->staff,
        chargesReviewed: true,
    );
})->throws(ValidationException::class);

test('overpayment is rejected', function () {
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
})->throws(ValidationException::class, 'exceeds the current balance');

test('exact balance payment succeeds', function () {
    $record = BillingRecord::factory()->create([
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 5000,
        paymentMethod: 'cash',
        recorder: $this->staff,
        chargesReviewed: true,
    );

    $record->refresh();
    expect($record->status)->toBe(BillingRecordStatus::Paid)
        ->and((float) $record->balance_due)->toBe(0.0);
});

test('valid deposits and partial payments retain behavior', function () {
    $record = BillingRecord::factory()->create([
        'total_amount' => 10000,
        'amount_paid' => 0,
        'balance_due' => 10000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    // First payment (deposit)
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

    // Second payment (partial)
    app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 2000,
        paymentMethod: 'gcash',
        recorder: $this->staff,
        chargesReviewed: true,
    );

    $record->refresh();
    expect((float) $record->amount_paid)->toBe(5000.0)
        ->and((float) $record->balance_due)->toBe(5000.0);
});

test('concurrent payments cannot overpay', function () {
    $record = BillingRecord::factory()->create([
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    // First payment succeeds
    app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 3000,
        paymentMethod: 'cash',
        recorder: $this->staff,
        chargesReviewed: true,
    );

    $record->refresh();

    // Second payment that would overpay is rejected
    app(RecordBillingPayment::class)->handle(
        billingRecord: $record,
        amount: 3000, // Would make total 6000 > 5000
        paymentMethod: 'cash',
        recorder: $this->staff,
        chargesReviewed: true,
    );
})->throws(ValidationException::class, 'exceeds the current balance');
