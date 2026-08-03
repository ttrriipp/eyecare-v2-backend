<?php

/**
 * Tests for Billing Record and Job Order factory states.
 */

use App\Enums\BillingRecordStatus;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('factory creates optical-only billing record', function () {
    $billing = BillingRecord::factory()->opticalOnly()->create();

    expect($billing->job_order_id)->not->toBeNull()
        ->and($billing->encounter_id)->toBeNull()
        ->and($billing->isOpticalOnly())->toBeTrue();
});

test('factory creates encounter-only billing record', function () {
    $billing = BillingRecord::factory()->encounterOnly()->create();

    expect($billing->job_order_id)->toBeNull()
        ->and($billing->encounter_id)->not->toBeNull()
        ->and($billing->isEncounterOnly())->toBeTrue();
});

test('factory creates combined billing record', function () {
    $billing = BillingRecord::factory()->combined()->create();

    expect($billing->job_order_id)->not->toBeNull()
        ->and($billing->encounter_id)->not->toBeNull()
        ->and($billing->isCombined())->toBeTrue();
});

test('factory creates billing with discount', function () {
    $billing = BillingRecord::factory()->withDiscount(1500)->create([
        'subtotal_amount' => 10000,
        'total_amount' => 8500,
        'balance_due' => 8500,
    ]);

    expect((float) $billing->subtotal_amount)->toBe(10000.0)
        ->and((float) $billing->discount_amount)->toBe(1500.0)
        ->and((float) $billing->total_amount)->toBe(8500.0);
});

test('factory creates job order with fulfillment defaults', function () {
    $jobOrder = JobOrder::factory()->create();

    expect($jobOrder->fulfillment_mode)->toBe('prepared')
        ->and($jobOrder->uses_external_supplier)->toBeFalse();
});

test('paid billing factory state', function () {
    $billing = BillingRecord::factory()->create([
        'status' => BillingRecordStatus::Paid,
        'subtotal_amount' => 5000,
        'discount_amount' => 0,
        'total_amount' => 5000,
        'amount_paid' => 5000,
        'balance_due' => 0,
    ]);

    expect($billing->status)->toBe(BillingRecordStatus::Paid)
        ->and((float) $billing->amount_paid)->toBe(5000.0)
        ->and((float) $billing->balance_due)->toBe(0.0);
});

test('voided billing factory state', function () {
    $billing = BillingRecord::factory()->voided()->create();

    expect($billing->status)->toBe(BillingRecordStatus::Voided)
        ->and($billing->voided_by)->not->toBeNull()
        ->and($billing->voided_at)->not->toBeNull()
        ->and($billing->void_reason)->toBe('Test void');
});
