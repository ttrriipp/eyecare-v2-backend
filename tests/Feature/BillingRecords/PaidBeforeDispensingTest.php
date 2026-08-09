<?php

/**
 * Tests for paid-before-dispensing requirement.
 *
 * @see tasks/todo.md Task 28
 */

use App\Actions\BillingRecords\DispenseJobOrder;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
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

test('routine actors cannot dispense while a balance remains', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'total_amount' => 5000,
    ]);

    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    app(DispenseJobOrder::class)->handle($jobOrder, $this->staff);
})->throws(ValidationException::class, 'outstanding balance');

test('exact pickup payment plus dispensing commits both effects once', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'total_amount' => 5000,
    ]);

    $billingRecord = BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    $event = app(DispenseJobOrder::class)->handle(
        $jobOrder,
        $this->staff,
        pickupPaymentAmount: 5000,
        pickupPaymentMethod: 'cash',
    );

    $billingRecord->refresh();
    expect((float) $billingRecord->balance_due)->toBe(0.0)
        ->and($billingRecord->status)->toBe(BillingRecordStatus::Paid)
        ->and($jobOrder->fresh()->status)->toBe(JobOrderStatus::Dispensed)
        ->and(DispensingEvent::where('job_order_id', $jobOrder->id)->count())->toBe(1);
});

test('insufficient pickup amount commits neither payment nor dispensing', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'total_amount' => 5000,
    ]);

    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    app(DispenseJobOrder::class)->handle(
        $jobOrder,
        $this->staff,
        pickupPaymentAmount: 3000,
        pickupPaymentMethod: 'cash',
    );
})->throws(ValidationException::class, 'outstanding balance');

test('paid billing record can dispense without pickup payment', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'total_amount' => 5000,
    ]);

    BillingRecord::factory()->paid()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    $event = app(DispenseJobOrder::class)->handle($jobOrder, $this->staff);

    expect($jobOrder->fresh()->status)->toBe(JobOrderStatus::Dispensed)
        ->and($event)->toBeInstanceOf(DispensingEvent::class);
});

test('separate partial payment before dispense works', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'total_amount' => 10000,
    ]);

    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 10000,
        'amount_paid' => 5000,
        'balance_due' => 5000,
        'status' => BillingRecordStatus::PartiallyPaid,
    ]);

    // Dispense with pickup payment to clear remaining balance
    $event = app(DispenseJobOrder::class)->handle(
        $jobOrder,
        $this->staff,
        pickupPaymentAmount: 5000,
        pickupPaymentMethod: 'gcash',
    );

    expect($jobOrder->fresh()->status)->toBe(JobOrderStatus::Dispensed);
});
