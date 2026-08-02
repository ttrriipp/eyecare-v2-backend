<?php

use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Http\Resources\EyewearDetailResource;
use App\Http\Resources\EyewearSummaryResource;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\Patient;
use App\Models\Quotation;
use App\Services\Eyewear\BuildEyewearAggregate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('summary resource matches spec fields', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Presented,
        'total' => 5000,
    ]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle($quotation, null);
    $resource = EyewearSummaryResource::make($aggregate)->resolve();

    expect($resource)->toHaveKeys([
        'key', 'description', 'consultation_at', 'created_at',
        'progress', 'payment_status', 'total_amount', 'amount_paid',
        'balance_due', 'payment_due_date', 'activity_at',
    ])
        ->and($resource['progress'])->toBe('estimate_available')
        ->and($resource['payment_status'])->toBeNull()
        ->and($resource['amount_paid'])->toBeNull()
        ->and($resource['balance_due'])->toBeNull()
        ->and($resource['payment_due_date'])->toBeNull();
});

test('detail resource includes estimate section when present', function () {
    $quotation = Quotation::factory()->create([
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
    $resource = EyewearDetailResource::make($aggregate)->resolve();

    expect($resource)->toHaveKey('estimate')
        ->and($resource['estimate']['quotation_number'])->toBe($quotation->quotation_number)
        ->and($resource['estimate']['status'])->toBe('presented');
});

test('detail resource omits estimate section for job-order-only', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);
    $resource = EyewearDetailResource::make($aggregate)->resolve();

    expect($resource)->not->toHaveKey('estimate');
});

test('detail resource includes preparation for job orders', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::InProgress]);
    JobOrderItem::factory()->create(['job_order_id' => $jobOrder->id]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);
    $resource = EyewearDetailResource::make($aggregate)->resolve();

    expect($resource)->toHaveKey('preparation')
        ->and($resource['preparation']['job_order_number'])->toBe($jobOrder->job_order_number);
});

test('detail resource includes dispensing only for ready or dispensed', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'ready_at' => now(),
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);
    $resource = EyewearDetailResource::make($aggregate)->resolve();

    expect($resource)->toHaveKey('dispensing')
        ->and($resource['dispensing']['status'])->toBe('ready_for_dispensing');
});

test('detail resource omits dispensing for queued', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Queued]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);
    $resource = EyewearDetailResource::make($aggregate)->resolve();

    expect($resource)->not->toHaveKey('dispensing');
});

test('detail resource includes payment summary for active billing', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Dispensed]);
    BillingRecord::factory()->partiallyPaid()->create([
        'job_order_id' => $jobOrder->id,
        'total_amount' => 8000,
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);
    $resource = EyewearDetailResource::make($aggregate)->resolve();

    expect($resource)->toHaveKey('payment_summary')
        ->and($resource['payment_summary']['status'])->toBe('partially_paid');
});

test('detail resource omits payment summary for voided billing', function () {
    $jobOrder = JobOrder::factory()->create(['status' => JobOrderStatus::Dispensed]);
    BillingRecord::factory()->voided()->create(['job_order_id' => $jobOrder->id]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);
    $resource = EyewearDetailResource::make($aggregate)->resolve();

    expect($resource)->not->toHaveKey('payment_summary');
});

test('money fields are exact two-decimal strings in resource', function () {
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
    $resource = EyewearSummaryResource::make($aggregate)->resolve();

    expect($resource['total_amount'])->toBe('1000.50');
});

test('internal fields are absent from resource', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Dispensed,
        'notes' => 'Internal note',
    ]);
    BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'notes' => 'Internal billing note',
    ]);

    $aggregate = app(BuildEyewearAggregate::class)->handle(null, $jobOrder);
    $resource = EyewearDetailResource::make($aggregate)->resolve();

    // No patient IDs, staff notes, recorder IDs, etc.
    $json = json_encode($resource);
    expect($json)->not->toContain('patient_id')
        ->and($json)->not->toContain('notes')
        ->and($json)->not->toContain('voided_by')
        ->and($json)->not->toContain('recorded_by');
});
