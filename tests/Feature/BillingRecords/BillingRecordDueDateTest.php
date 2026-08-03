<?php

/**
 * Tests for direct Job Order and Billing Record fields introduced in Task A4.
 *
 * Verifies:
 * - JobOrder::quotation() uses direct quotation_id
 * - BillingRecord::payment_due_date is date-cast
 * - Overdue derivation: active unpaid balance with due date before today
 * - Paid and voided records are never overdue
 * - Factories create valid direct aggregate records
 */

use App\Enums\BillingRecordStatus;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\Quotation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('job order has direct quotation relationship', function () {
    $quotation = Quotation::factory()->create();

    $jobOrder = JobOrder::factory()->create([
        'quotation_id' => $quotation->id,
    ]);

    expect($jobOrder->quotation)->not->toBeNull()
        ->and($jobOrder->quotation->id)->toBe($quotation->id);
});

test('billing record payment due date is date cast', function () {
    $billing = BillingRecord::factory()->create([
        'payment_due_date' => '2026-09-15',
    ]);

    expect($billing->payment_due_date)->toBeInstanceOf(Carbon::class)
        ->and($billing->payment_due_date->format('Y-m-d'))->toBe('2026-09-15');
});

test('overdue when active unpaid balance with due date before today', function () {
    $billing = BillingRecord::factory()->create([
        'status' => BillingRecordStatus::Unpaid,
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'payment_due_date' => today()->subDays(5),
    ]);

    expect($billing->isOverdue())->toBeTrue();
});

test('overdue when partially paid with due date before today', function () {
    $billing = BillingRecord::factory()->create([
        'status' => BillingRecordStatus::PartiallyPaid,
        'total_amount' => 5000,
        'amount_paid' => 2000,
        'balance_due' => 3000,
        'payment_due_date' => today()->subDays(3),
    ]);

    expect($billing->isOverdue())->toBeTrue();
});

test('not overdue when due date is in the future', function () {
    $billing = BillingRecord::factory()->create([
        'status' => BillingRecordStatus::Unpaid,
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'payment_due_date' => today()->addDays(10),
    ]);

    expect($billing->isOverdue())->toBeFalse();
});

test('not overdue when fully paid', function () {
    $billing = BillingRecord::factory()->create([
        'status' => BillingRecordStatus::Paid,
        'total_amount' => 5000,
        'amount_paid' => 5000,
        'balance_due' => 0,
        'payment_due_date' => today()->subDays(5),
    ]);

    expect($billing->isOverdue())->toBeFalse();
});

test('not overdue when voided', function () {
    $billing = BillingRecord::factory()->create([
        'status' => BillingRecordStatus::Voided,
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'payment_due_date' => today()->subDays(5),
    ]);

    expect($billing->isOverdue())->toBeFalse();
});

test('not overdue when no due date set', function () {
    $billing = BillingRecord::factory()->create([
        'status' => BillingRecordStatus::Unpaid,
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'payment_due_date' => null,
    ]);

    expect($billing->isOverdue())->toBeFalse();
});

test('not overdue when balance is zero', function () {
    $billing = BillingRecord::factory()->create([
        'status' => BillingRecordStatus::Unpaid,
        'total_amount' => 5000,
        'amount_paid' => 5000,
        'balance_due' => 0,
        'payment_due_date' => today()->subDays(5),
    ]);

    expect($billing->isOverdue())->toBeFalse();
});

test('factory creates job order with direct quotation link', function () {
    $quotation = Quotation::factory()->create();

    $jobOrder = JobOrder::factory()->create([
        'quotation_id' => $quotation->id,
        'patient_id' => $quotation->patient_id,
    ]);

    expect($jobOrder->quotation_id)->toBe($quotation->id)
        ->and($jobOrder->quotation->id)->toBe($quotation->id);
});

test('factory creates billing record with due date', function () {
    $billing = BillingRecord::factory()->create([
        'payment_due_date' => today()->addMonth(),
    ]);

    expect($billing->payment_due_date)->not->toBeNull()
        ->and($billing->payment_due_date->isFuture())->toBeTrue();
});
