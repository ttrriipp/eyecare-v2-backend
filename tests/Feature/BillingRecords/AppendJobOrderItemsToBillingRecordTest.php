<?php

use App\Actions\BillingRecords\AppendJobOrderItemsToBillingRecord;
use App\Enums\TransactionItemType;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->action = app(AppendJobOrderItemsToBillingRecord::class);
});

test('appends job order items to billing record', function () {
    $patient = Patient::factory()->create();
    $jobOrder = JobOrder::factory()->create(['patient_id' => $patient->id]);
    $billing = BillingRecord::factory()->create(['patient_id' => $patient->id]);

    JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'item_type' => TransactionItemType::Product,
    ]);

    $this->action->handle($jobOrder, $billing);

    expect($billing->fresh()->items)->toHaveCount(1)
        ->and($billing->fresh()->items->first()->description)->toBe('Frame');
});

test('idempotent - repeated call adds no duplicates', function () {
    $patient = Patient::factory()->create();
    $jobOrder = JobOrder::factory()->create(['patient_id' => $patient->id]);
    $billing = BillingRecord::factory()->create(['patient_id' => $patient->id]);

    JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $this->action->handle($jobOrder, $billing);
    $this->action->handle($jobOrder, $billing);

    expect($billing->fresh()->items)->toHaveCount(1);
});

test('applies discount amount', function () {
    $patient = Patient::factory()->create();
    $jobOrder = JobOrder::factory()->create(['patient_id' => $patient->id]);
    $billing = BillingRecord::factory()->create(['patient_id' => $patient->id]);

    JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 10000,
        'amount' => 10000,
    ]);

    $this->action->handle($jobOrder, $billing, discountAmount: 1500);

    $billing = $billing->fresh();

    expect((float) $billing->subtotal_amount)->toBe(10000.0)
        ->and((float) $billing->discount_amount)->toBe(1500.0)
        ->and((float) $billing->total_amount)->toBe(8500.0);
});

test('rejects patient mismatch', function () {
    $patient1 = Patient::factory()->create();
    $patient2 = Patient::factory()->create();
    $jobOrder = JobOrder::factory()->create(['patient_id' => $patient1->id]);
    $billing = BillingRecord::factory()->create(['patient_id' => $patient2->id]);

    $this->action->handle($jobOrder, $billing);
})->throws(ValidationException::class, 'Patient mismatch');

test('rejects billing with posted payments', function () {
    $patient = Patient::factory()->create();
    $jobOrder = JobOrder::factory()->create(['patient_id' => $patient->id]);
    $billing = BillingRecord::factory()->create(['patient_id' => $patient->id]);

    // Add a posted payment
    $billing->payments()->create([
        'amount' => 1000,
        'payment_method' => 'cash',
        'status' => 'posted',
        'recorded_by' => User::factory()->create()->id,
        'recorded_at' => now(),
    ]);

    $this->action->handle($jobOrder, $billing);
})->throws(ValidationException::class, 'posted payments');
