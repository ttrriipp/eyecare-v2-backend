<?php

/**
 * Tests for Billing Record item and source relationships.
 */

use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('billing record owns items', function () {
    $billing = BillingRecord::factory()->create();

    BillingRecordItem::factory()->count(3)->create([
        'billing_record_id' => $billing->id,
    ]);

    expect($billing->items)->toHaveCount(3);
});

test('optical-only source context', function () {
    $jobOrder = JobOrder::factory()->create();
    $billing = BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'encounter_id' => null,
    ]);

    expect($billing->getSourceContext())->toBe('Optical Order')
        ->and($billing->isOpticalOnly())->toBeTrue()
        ->and($billing->isEncounterOnly())->toBeFalse()
        ->and($billing->isCombined())->toBeFalse();
});

test('encounter-only source context', function () {
    $encounter = Encounter::factory()->create();
    $billing = BillingRecord::factory()->create([
        'job_order_id' => null,
        'encounter_id' => $encounter->id,
    ]);

    expect($billing->getSourceContext())->toBe('Encounter')
        ->and($billing->isEncounterOnly())->toBeTrue()
        ->and($billing->isOpticalOnly())->toBeFalse()
        ->and($billing->isCombined())->toBeFalse();
});

test('combined source context', function () {
    $jobOrder = JobOrder::factory()->create();
    $encounter = Encounter::factory()->create();
    $billing = BillingRecord::factory()->create([
        'job_order_id' => $jobOrder->id,
        'encounter_id' => $encounter->id,
    ]);

    expect($billing->getSourceContext())->toBe('Combined')
        ->and($billing->isCombined())->toBeTrue()
        ->and($billing->isOpticalOnly())->toBeFalse()
        ->and($billing->isEncounterOnly())->toBeFalse();
});

test('billing record item origin tracking', function () {
    $billing = BillingRecord::factory()->create();

    $opticalItem = BillingRecordItem::factory()->create([
        'billing_record_id' => $billing->id,
        'job_order_item_id' => JobOrderItem::factory()->create()->id,
        'encounter_id' => null,
    ]);

    $encounterItem = BillingRecordItem::factory()->create([
        'billing_record_id' => $billing->id,
        'job_order_item_id' => null,
        'encounter_id' => Encounter::factory()->create()->id,
    ]);

    expect($opticalItem->isFromOpticalOrder())->toBeTrue()
        ->and($opticalItem->isFromEncounter())->toBeFalse()
        ->and($encounterItem->isFromEncounter())->toBeTrue()
        ->and($encounterItem->isFromOpticalOrder())->toBeFalse();
});

test('billing record subtotal and discount casts', function () {
    $billing = BillingRecord::factory()->create([
        'subtotal_amount' => 10000,
        'discount_amount' => 1500,
        'total_amount' => 8500,
    ]);

    expect((float) $billing->subtotal_amount)->toBe(10000.0)
        ->and((float) $billing->discount_amount)->toBe(1500.0)
        ->and((float) $billing->total_amount)->toBe(8500.0);
});
