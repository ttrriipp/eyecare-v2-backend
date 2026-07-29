<?php

use App\Enums\BillingRecordStatus;
use App\Enums\EyewearPaymentStatus;
use App\Enums\EyewearProgress;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\Appointment;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Patient;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationRevision;
use App\Services\Eyewear\BuildEyewearAggregate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('presented estimate maps to estimate_available', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Presented,
    ]);
    $revision = QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'subtotal' => 5000,
        'total' => 5000,
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
    ]);
    QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'subtotal' => 8000,
        'discount_amount' => 500,
        'total' => 7500,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->progress)->toBe(EyewearProgress::EstimateAvailable)
        ->and($aggregate->totalAmount)->toBe('7500.00');
});

test('declined estimate maps to estimate_declined', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Declined]);
    QuotationRevision::factory()->create(['quotation_id' => $quotation->id]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->progress)->toBe(EyewearProgress::EstimateDeclined);
});

test('expired estimate maps to estimate_expired', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Expired]);
    QuotationRevision::factory()->create(['quotation_id' => $quotation->id]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->progress)->toBe(EyewearProgress::EstimateExpired);
});

test('draft estimate cannot produce an aggregate', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);

    app(BuildEyewearAggregate::class)->handle($quotation, null);
})->throws(InvalidArgumentException::class);

test('estimate section includes items from latest revision', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);
    $revision = QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'subtotal' => 8500,
        'discount_amount' => 500,
        'total' => 8000,
    ]);
    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'description' => 'Classic Rectangle Frame',
        'quantity' => 1,
        'unit_price' => 4500,
        'amount' => 4500,
    ]);
    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'description' => 'Single Vision Lens',
        'quantity' => 1,
        'unit_price' => 4000,
        'amount' => 4000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->estimate)->not->toBeNull()
        ->and($aggregate->estimate['quotation_number'])->toBe($quotation->quotation_number)
        ->and($aggregate->estimate['status'])->toBe('presented')
        ->and($aggregate->estimate['subtotal'])->toBe('8500.00')
        ->and($aggregate->estimate['discount_amount'])->toBe('500.00')
        ->and($aggregate->estimate['total'])->toBe('8000.00')
        ->and($aggregate->estimate['items'])->toHaveCount(2)
        ->and($aggregate->estimate['items'][0]['description'])->toBe('Classic Rectangle Frame')
        ->and($aggregate->estimate['items'][0]['unit_price'])->toBe('4500.00');
});

test('money values are exact two-decimal strings', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);
    QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'subtotal' => 1000.5,
        'total' => 1000.5,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->totalAmount)->toBe('1000.50')
        ->and($aggregate->estimate['subtotal'])->toBe('1000.50')
        ->and($aggregate->estimate['total'])->toBe('1000.50');
});

test('description uses first item with count for multiple items', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);
    $revision = QuotationRevision::factory()->create(['quotation_id' => $quotation->id]);
    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'description' => 'Classic Rectangle Frame',
    ]);
    QuotationItem::factory()->create([
        'quotation_revision_id' => $revision->id,
        'description' => 'Single Vision Lens',
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->description)->toBe('Classic Rectangle Frame + 1 more');
});

test('description falls back to Eyewear transaction when no items', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);
    QuotationRevision::factory()->create(['quotation_id' => $quotation->id]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->description)->toBe('Eyewear transaction');
});

test('consultation timestamp resolves from encounter appointment', function () {
    $patient = Patient::factory()->create();
    $encounter = Encounter::factory()->create(['patient_id' => $patient->id]);
    $appointment = Appointment::factory()->create([
        'patient_id' => $patient->id,
        'scheduled_at' => '2026-07-27T09:00:00+08:00',
    ]);
    $encounter->update(['appointment_id' => $appointment->id]);

    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'encounter_id' => $encounter->id,
        'status' => QuotationStatus::Presented,
    ]);
    QuotationRevision::factory()->create(['quotation_id' => $quotation->id]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->consultationAt)->toBe('2026-07-27T09:00:00+08:00');
});

test('consultation timestamp is null when no encounter link', function () {
    $quotation = Quotation::factory()->create([
        'encounter_id' => null,
        'status' => QuotationStatus::Presented,
    ]);
    QuotationRevision::factory()->create(['quotation_id' => $quotation->id]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->consultationAt)->toBeNull();
});

test('queued job order maps to in_preparation', function () {
    $patient = Patient::factory()->create();
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Accepted,
    ]);
    $jobOrder = JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'status' => JobOrderStatus::Queued,
        'total_amount' => 5000,
    ]);
    JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'description' => 'Frame A',
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, $jobOrder);

    expect($aggregate->progress)->toBe(EyewearProgress::InPreparation)
        ->and($aggregate->preparation)->not->toBeNull()
        ->and($aggregate->preparation['status'])->toBe('queued')
        ->and($aggregate->dispensing)->toBeNull();
});

test('in-progress job order maps to in_preparation', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::InProgress,
        'started_at' => now(),
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);

    expect($aggregate->progress)->toBe(EyewearProgress::InPreparation);
});

test('ready-for-dispensing job order maps to ready_for_pickup', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'ready_at' => now(),
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);

    expect($aggregate->progress)->toBe(EyewearProgress::ReadyForPickup)
        ->and($aggregate->dispensing)->not->toBeNull()
        ->and($aggregate->dispensing['status'])->toBe('ready_for_dispensing');
});

test('dispensed job order maps to dispensed', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Dispensed,
        'dispensed_at' => now(),
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);

    expect($aggregate->progress)->toBe(EyewearProgress::Dispensed)
        ->and($aggregate->dispensing)->not->toBeNull()
        ->and($aggregate->dispensing['status'])->toBe('dispensed');
});

test('cancelled job order maps to cancelled', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Cancelled,
        'cancelled_at' => now(),
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);

    expect($aggregate->progress)->toBe(EyewearProgress::Cancelled);
});

test('linked job order overrides inconsistent quotation progress', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Declined]);
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::InProgress]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, $jobOrder);

    expect($aggregate->progress)->toBe(EyewearProgress::InPreparation);
});

test('preparation section includes job order items', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Queued,
        'total_amount' => 8000,
    ]);
    JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'description' => 'Classic Rectangle Frame',
        'quantity' => 1,
        'unit_price' => 4500,
        'amount' => 4500,
        'product_variant_id' => null,
    ]);
    JobOrderItem::factory()->create([
        'job_order_id' => $jobOrder->id,
        'description' => 'Single Vision Lens',
        'quantity' => 1,
        'unit_price' => 4000,
        'amount' => 4000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);

    expect($aggregate->preparation['items'])->toHaveCount(2)
        ->and($aggregate->preparation['items'][0]['description'])->toBe('Classic Rectangle Frame')
        ->and($aggregate->preparation['items'][0]['product_variant_id'])->toBeNull()
        ->and($aggregate->preparation['items'][1]['product_variant_id'])->toBeNull();
});

test('dispensing section omitted for queued job order', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);

    expect($aggregate->dispensing)->toBeNull();
});

test('expected_completion_at is absent from preparation', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);

    expect($aggregate->preparation)->not->toHaveKey('expected_completion_at');
});

test('unpaid billing record maps to balance_due', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Dispensed]);
    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'status' => BillingRecordStatus::Unpaid,
        'total_amount' => 8000,
        'amount_paid' => 0,
        'balance_due' => 8000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);

    expect($aggregate->paymentStatus)->toBe(EyewearPaymentStatus::BalanceDue)
        ->and($aggregate->balanceDue)->toBe('8000.00')
        ->and($aggregate->paymentSummary)->not->toBeNull()
        ->and($aggregate->paymentSummary['status'])->toBe('unpaid');
});

test('partially_paid billing record maps to balance_due', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Dispensed]);
    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'status' => BillingRecordStatus::PartiallyPaid,
        'total_amount' => 8000,
        'amount_paid' => 5000,
        'balance_due' => 3000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);

    expect($aggregate->paymentStatus)->toBe(EyewearPaymentStatus::BalanceDue)
        ->and($aggregate->balanceDue)->toBe('3000.00');
});

test('paid billing record maps to paid', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Dispensed]);
    BillingRecord::factory()->paid()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 8000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);

    expect($aggregate->paymentStatus)->toBe(EyewearPaymentStatus::Paid)
        ->and($aggregate->balanceDue)->toBe('0.00');
});

test('no billing record produces null payment fields', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::InProgress]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);

    expect($aggregate->paymentStatus)->toBeNull()
        ->and($aggregate->balanceDue)->toBeNull()
        ->and($aggregate->paymentSummary)->toBeNull();
});

test('voided billing record is ignored', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Dispensed]);
    BillingRecord::factory()->voided()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 8000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);

    expect($aggregate->paymentStatus)->toBeNull()
        ->and($aggregate->balanceDue)->toBeNull()
        ->and($aggregate->paymentSummary)->toBeNull();
});

test('total precedence billing record over job order', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Dispensed,
        'total_amount' => 5000,
    ]);
    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 8000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);

    expect($aggregate->totalAmount)->toBe('8000.00');
});

test('total precedence job order over estimate', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Accepted]);
    $revision = QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'total' => 3000,
    ]);
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Queued,
        'quotation_revision_id' => $revision->id,
        'total_amount' => 5000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, $jobOrder);

    expect($aggregate->totalAmount)->toBe('5000.00');
});

test('total falls back to estimate when no billing or job order', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Presented]);
    QuotationRevision::factory()->create([
        'quotation_id' => $quotation->id,
        'total' => 7500,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);

    expect($aggregate->totalAmount)->toBe('7500.00');
});

test('payment summary includes only posted payments', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Dispensed]);
    $billingRecord = BillingRecord::factory()->partiallyPaid()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 8000,
    ]);

    BillingPayment::factory()->create([
        'billing_record_id' => $billingRecord->id,
        'amount' => 5000,
        'payment_method' => 'cash',
        'status' => 'posted',
        'recorded_at' => now()->subHour(),
    ]);

    BillingPayment::factory()->create([
        'billing_record_id' => $billingRecord->id,
        'amount' => 1000,
        'payment_method' => 'gcash',
        'status' => 'reversed',
        'recorded_at' => now(),
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);

    expect($aggregate->paymentSummary['payments'])->toHaveCount(1)
        ->and($aggregate->paymentSummary['payments'][0]['payment_method'])->toBe('cash');
});

test('activity timestamp includes payment recorded_at', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Dispensed,
        'dispensed_at' => now()->subDays(2),
    ]);
    $billingRecord = BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'recorded_at' => now()->subDay(),
    ]);
    BillingPayment::factory()->create([
        'billing_record_id' => $billingRecord->id,
        'status' => 'posted',
        'recorded_at' => now(),
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);

    expect($aggregate->activityAt)->not->toBeNull();
});

test('dispensed with balance remains in history mapping', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Dispensed]);
    BillingRecord::factory()->partiallyPaid()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 8000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);

    expect($aggregate->progress)->toBe(EyewearProgress::Dispensed)
        ->and($aggregate->paymentStatus)->toBe(EyewearPaymentStatus::BalanceDue);
});

test('internal notes recorder ids and void data are absent', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Dispensed]);
    $billingRecord = BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'notes' => 'Internal billing note',
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);

    expect($aggregate->paymentSummary)->not->toHaveKey('notes')
        ->and($aggregate->paymentSummary)->not->toHaveKey('voided_by')
        ->and($aggregate->paymentSummary)->not->toHaveKey('void_reason');
});
