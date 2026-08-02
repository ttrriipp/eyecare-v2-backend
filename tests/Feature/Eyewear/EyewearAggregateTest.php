<?php

use App\Enums\BillingRecordStatus;
use App\Enums\EyewearPaymentStatus;
use App\Enums\EyewearProgress;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Quotation;
use App\Services\Eyewear\BuildEyewearAggregate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('presented estimate maps to estimate_available', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Presented,
        'subtotal' => 5000,
        'total' => 5000,
    ]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->progress)->toBe(EyewearProgress::EstimateAvailable)
        ->and($aggregate->key)->toBe($quotation->eyewear_key)
        ->and($aggregate->totalAmount)->toBe('5000.00')
        ->and($aggregate->preparation)->toBeNull()
        ->and($aggregate->dispensing)->toBeNull()
        ->and($aggregate->paymentSummary)->toBeNull();
});

test('accepted estimate maps to estimate_available', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Accepted,
        'subtotal' => 8000,
        'discount_amount' => 500,
        'total' => 7500,
    ]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 7500,
        'amount' => 7500,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->progress)->toBe(EyewearProgress::EstimateAvailable)
        ->and($aggregate->totalAmount)->toBe('7500.00');
});

test('declined estimate maps to estimate_declined', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Declined,
        'total' => 5000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->progress)->toBe(EyewearProgress::EstimateDeclined);
});

test('expired estimate maps to estimate_expired', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Expired,
        'total' => 5000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->progress)->toBe(EyewearProgress::EstimateExpired);
});

test('draft quotation throws exception', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);

    app(BuildEyewearAggregate::class)->handle($quotation, null);
})->throws(InvalidArgumentException::class);

test('job order queued maps to in_preparation', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Accepted,
        'total' => 5000,
    ]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'quotation_id' => $quotation->id,
        'status' => JobOrderStatus::Queued,
        'total_amount' => 5000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, $jobOrder);

    expect($aggregate->progress)->toBe(EyewearProgress::InPreparation);
});

test('job order in_progress maps to in_preparation', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Accepted,
        'total' => 5000,
    ]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'quotation_id' => $quotation->id,
        'status' => JobOrderStatus::InProgress,
        'total_amount' => 5000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, $jobOrder);

    expect($aggregate->progress)->toBe(EyewearProgress::InPreparation);
});

test('job order ready_for_dispensing maps to ready_for_pickup', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Accepted,
        'total' => 5000,
    ]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'quotation_id' => $quotation->id,
        'status' => JobOrderStatus::ReadyForDispensing,
        'total_amount' => 5000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, $jobOrder);

    expect($aggregate->progress)->toBe(EyewearProgress::ReadyForPickup);
});

test('job order dispensed maps to dispensed', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Accepted,
        'total' => 5000,
    ]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'quotation_id' => $quotation->id,
        'status' => JobOrderStatus::Dispensed,
        'total_amount' => 5000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, $jobOrder);

    expect($aggregate->progress)->toBe(EyewearProgress::Dispensed);
});

test('job order cancelled maps to cancelled', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Accepted,
        'total' => 5000,
    ]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'quotation_id' => $quotation->id,
        'status' => JobOrderStatus::Cancelled,
        'total_amount' => 5000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, $jobOrder);

    expect($aggregate->progress)->toBe(EyewearProgress::Cancelled);
});

test('estimate section includes items from quotation', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Presented,
        'subtotal' => 8500,
        'discount_amount' => 500,
        'total' => 8000,
    ]);
    $quotation->items()->createMany([
        ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 5000, 'amount' => 5000],
        ['description' => 'Lens', 'quantity' => 2, 'unit_price' => 1750, 'amount' => 3500],
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->estimate)->not->toBeNull()
        ->and($aggregate->estimate['total'])->toBe('8000.00')
        ->and($aggregate->estimate['subtotal'])->toBe('8500.00')
        ->and($aggregate->estimate['discount_amount'])->toBe('500.00')
        ->and($aggregate->estimate['items'])->toHaveCount(2);
});

test('money values are exact two-decimal strings', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Presented,
        'subtotal' => 1000.50,
        'total' => 1000.50,
    ]);
    $quotation->items()->create([
        'description' => 'Item',
        'quantity' => 1,
        'unit_price' => 1000.50,
        'amount' => 1000.50,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->totalAmount)->toBe('1000.50')
        ->and($aggregate->estimate['total'])->toBe('1000.50');
});

test('preparation section includes job order items', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Accepted,
        'total' => 5000,
    ]);
    $jobOrder = JobOrder::factory()->create([
        'quotation_id' => $quotation->id,
        'status' => JobOrderStatus::Queued,
        'total_amount' => 5000,
    ]);
    $jobOrder->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, $jobOrder);

    expect($aggregate->preparation)->not->toBeNull()
        ->and($aggregate->preparation['items'])->toHaveCount(1)
        ->and($aggregate->preparation['items'][0]['description'])->toBe('Frame');
});

test('dispensing section present when ready or dispensed', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Accepted,
        'total' => 5000,
    ]);
    $jobOrder = JobOrder::factory()->create([
        'quotation_id' => $quotation->id,
        'status' => JobOrderStatus::ReadyForDispensing,
        'total_amount' => 5000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, $jobOrder);

    expect($aggregate->dispensing)->not->toBeNull()
        ->and($aggregate->dispensing['status'])->toBe('ready_for_dispensing');
});

test('dispensing section absent when queued', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Accepted,
        'total' => 5000,
    ]);
    $jobOrder = JobOrder::factory()->create([
        'quotation_id' => $quotation->id,
        'status' => JobOrderStatus::Queued,
        'total_amount' => 5000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, $jobOrder);

    expect($aggregate->dispensing)->toBeNull();
});

test('payment summary includes billing and payments', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Accepted,
        'total' => 10000,
    ]);
    $jobOrder = JobOrder::factory()->create([
        'quotation_id' => $quotation->id,
        'status' => JobOrderStatus::InProgress,
        'total_amount' => 10000,
    ]);
    $billing = BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'status' => BillingRecordStatus::PartiallyPaid,
        'total_amount' => 10000,
        'amount_paid' => 3000,
        'balance_due' => 7000,
    ]);
    BillingPayment::factory()->create([
        'billing_record_id' => $billing->id,
        'amount' => 3000,
        'status' => 'posted',
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, $jobOrder);

    expect($aggregate->paymentSummary)->not->toBeNull()
        ->and($aggregate->paymentSummary['total_amount'])->toBe('10000.00')
        ->and($aggregate->paymentSummary['amount_paid'])->toBe('3000.00')
        ->and($aggregate->paymentSummary['balance_due'])->toBe('7000.00')
        ->and($aggregate->paymentSummary['payments'])->toHaveCount(1)
        ->and($aggregate->paymentStatus)->toBe(EyewearPaymentStatus::BalanceDue);
});

test('paid payment status', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Accepted,
        'total' => 5000,
    ]);
    $jobOrder = JobOrder::factory()->create([
        'quotation_id' => $quotation->id,
        'status' => JobOrderStatus::Dispensed,
        'total_amount' => 5000,
    ]);
    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'status' => BillingRecordStatus::Paid,
        'total_amount' => 5000,
        'amount_paid' => 5000,
        'balance_due' => 0,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, $jobOrder);

    expect($aggregate->paymentStatus)->toBe(EyewearPaymentStatus::Paid);
});

test('description uses first item with count for multiple items', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Presented,
        'total' => 8000,
    ]);
    $quotation->items()->createMany([
        ['description' => 'Classic Rectangle Frame', 'quantity' => 1, 'unit_price' => 5000, 'amount' => 5000],
        ['description' => 'Single Vision Lens', 'quantity' => 1, 'unit_price' => 3000, 'amount' => 3000],
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->description)->toBe('Classic Rectangle Frame + 1 more');
});

test('description uses single item name when only one', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Presented,
        'total' => 5000,
    ]);
    $quotation->items()->create([
        'description' => 'Progressive Lens Package',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->description)->toBe('Progressive Lens Package');
});

test('description falls back to default when no items', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Presented,
        'total' => 0,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->description)->toBe('Eyewear transaction');
});

test('total falls back to estimate when no billing or job order', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Presented,
        'total' => 7500,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->totalAmount)->toBe('7500.00')
        ->and($aggregate->balanceDue)->toBeNull();
});

test('key comes from job order when both exist', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Accepted,
        'eyewear_key' => 'eyw_quotation_key',
        'total' => 5000,
    ]);
    $jobOrder = JobOrder::factory()->create([
        'quotation_id' => $quotation->id,
        'status' => JobOrderStatus::Queued,
        'eyewear_key' => 'eyw_job_order_key',
        'total_amount' => 5000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, $jobOrder);

    expect($aggregate->key)->toBe('eyw_job_order_key');
});

test('key comes from quotation when no job order', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Presented,
        'eyewear_key' => 'eyw_quotation_only',
        'total' => 5000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->key)->toBe('eyw_quotation_only');
});

test('job order total is used when billing exists', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Accepted,
        'total' => 5000,
    ]);
    $jobOrder = JobOrder::factory()->create([
        'quotation_id' => $quotation->id,
        'status' => JobOrderStatus::Queued,
        'total_amount' => 4500,
    ]);
    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 4500,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, $jobOrder);

    expect($aggregate->totalAmount)->toBe('4500.00');
});

test('estimate total is used when no job order', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Presented,
        'total' => 7500,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->totalAmount)->toBe('7500.00');
});

test('key must exist from at least one source', function () {
    app(BuildEyewearAggregate::class)->handle(null, null);
})->throws(InvalidArgumentException::class);
