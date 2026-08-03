<?php

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
    $this->dispenser = User::factory()->staff()->create();
    $this->actingAs($this->dispenser);
});

test('dispensing requires existing billing record', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'supplier_invoice_number' => 'INV-001',
    ]);

    app(DispenseJobOrder::class)->handle($jobOrder, $this->dispenser);
})->throws(ValidationException::class, 'No billing record found');

test('dispensing reuses existing billing record', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'total_amount' => 5000,
        'supplier_invoice_number' => 'INV-001',
    ]);

    $billingRecord = BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'status' => BillingRecordStatus::Unpaid,
        'total_amount' => 5000,
        'amount_paid' => 0,
        'balance_due' => 5000,
    ]);

    $event = app(DispenseJobOrder::class)->handle(
        $jobOrder,
        $this->dispenser,
        recipientName: 'Patient',
    );

    expect($event)->toBeInstanceOf(DispensingEvent::class)
        ->and($event->billing_record_id)->toBe($billingRecord->id)
        ->and($jobOrder->fresh()->status)->toBe(JobOrderStatus::Dispensed);

    // Billing record is NOT duplicated
    expect(BillingRecord::where('job_order_id', $jobOrder->id)->count())->toBe(1);
});

test('dispensing with pickup payment updates billing', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'total_amount' => 10000,
        'supplier_invoice_number' => 'INV-002',
    ]);

    $billingRecord = BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'status' => BillingRecordStatus::PartiallyPaid,
        'total_amount' => 10000,
        'amount_paid' => 3000,
        'balance_due' => 7000,
    ]);

    app(DispenseJobOrder::class)->handle(
        $jobOrder,
        $this->dispenser,
        pickupPaymentAmount: 7000,
        pickupPaymentMethod: 'gcash',
    );

    $billingRecord = $billingRecord->fresh();

    expect((float) $billingRecord->amount_paid)->toBe(10000.0)
        ->and((float) $billingRecord->balance_due)->toBe(0.0)
        ->and($billingRecord->status)->toBe(BillingRecordStatus::Paid);
});

test('dispensing against voided billing is rejected', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'supplier_invoice_number' => 'INV-003',
    ]);

    BillingRecord::factory()->voided()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    app(DispenseJobOrder::class)->handle($jobOrder, $this->dispenser);
})->throws(ValidationException::class, 'No billing record found');

test('only ready-for-dispensing can be dispensed', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Queued,
        'supplier_invoice_number' => 'INV-004',
    ]);

    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    app(DispenseJobOrder::class)->handle($jobOrder, $this->dispenser);
})->throws(ValidationException::class);

test('dispensing records event with recipient and notes', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'supplier_invoice_number' => 'INV-005',
    ]);

    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
    ]);

    $event = app(DispenseJobOrder::class)->handle(
        $jobOrder,
        $this->dispenser,
        recipientName: 'Juan dela Cruz',
        notes: 'Picked up by patient',
    );

    expect($event->recipient_name)->toBe('Juan dela Cruz')
        ->and($event->notes)->toBe('Picked up by patient')
        ->and($event->dispensed_by)->toBe($this->dispenser->id);
});
