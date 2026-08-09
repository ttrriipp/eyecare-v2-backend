<?php

/**
 * Tests for admin balance override at dispensing.
 *
 * @see tasks/todo.md Task 29
 */

use App\Actions\BillingRecords\DispenseJobOrder;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->staff = User::factory()->staff()->create();
    $this->optometrist = User::factory()->optometrist()->create();
    $this->actingAs($this->admin);
});

test('admin can release with balance override', function () {
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

    $event = app(DispenseJobOrder::class)->handle(
        $jobOrder,
        $this->admin,
        adminOverride: true,
        overrideReason: 'Patient hardship - agreed to pay next week',
        overrideDueDate: now()->addWeek()->format('Y-m-d'),
    );

    expect($event->released_balance_amount)->toBe('5000.00')
        ->and($event->balance_override_by)->toBe($this->admin->id)
        ->and($event->balance_override_reason)->toBe('Patient hardship - agreed to pay next week')
        ->and($event->balance_due_date)->not->toBeNull()
        ->and($jobOrder->fresh()->status)->toBe(JobOrderStatus::Dispensed);
});

test('staff cannot override', function () {
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

    $this->actingAs($this->staff);

    app(DispenseJobOrder::class)->handle(
        $jobOrder,
        $this->staff,
        adminOverride: true,
        overrideReason: 'Test',
        overrideDueDate: now()->addWeek()->format('Y-m-d'),
    );
})->throws(ValidationException::class, 'outstanding balance');

test('optometrist cannot override', function () {
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

    $this->actingAs($this->optometrist);

    app(DispenseJobOrder::class)->handle(
        $jobOrder,
        $this->optometrist,
        adminOverride: true,
        overrideReason: 'Test',
        overrideDueDate: now()->addWeek()->format('Y-m-d'),
    );
})->throws(ValidationException::class, 'outstanding balance');

test('override requires reason', function () {
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

    app(DispenseJobOrder::class)->handle(
        $jobOrder,
        $this->admin,
        adminOverride: true,
        overrideReason: null,
        overrideDueDate: now()->addWeek()->format('Y-m-d'),
    );
})->throws(ValidationException::class, 'Provide a reason');

test('override requires due date', function () {
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

    app(DispenseJobOrder::class)->handle(
        $jobOrder,
        $this->admin,
        adminOverride: true,
        overrideReason: 'Patient hardship',
        overrideDueDate: null,
    );
})->throws(ValidationException::class, 'Provide a payment due date');

test('dual-role owner can override', function () {
    $dualRole = User::factory()->adminOptometrist()->create();

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

    $this->actingAs($dualRole);

    $event = app(DispenseJobOrder::class)->handle(
        $jobOrder,
        $dualRole,
        adminOverride: true,
        overrideReason: 'Owner discretion',
        overrideDueDate: now()->addWeek()->format('Y-m-d'),
    );

    expect($event->balance_override_by)->toBe($dualRole->id);
});

test('dispensing event stores override attribution', function () {
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

    $event = app(DispenseJobOrder::class)->handle(
        $jobOrder,
        $this->admin,
        adminOverride: true,
        overrideReason: 'Special case',
        overrideDueDate: '2026-12-31',
    );

    expect($event->released_balance_amount)->toBe('5000.00')
        ->and($event->balance_override_by)->toBe($this->admin->id)
        ->and($event->balance_override_reason)->toBe('Special case')
        ->and($event->balance_due_date->format('Y-m-d'))->toBe('2026-12-31');
});
