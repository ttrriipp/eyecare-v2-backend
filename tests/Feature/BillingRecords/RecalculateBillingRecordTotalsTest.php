<?php

use App\Actions\BillingRecords\RecalculateBillingRecordTotals;
use App\Enums\BillingRecordStatus;
use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->recalculate = app(RecalculateBillingRecordTotals::class);
});

test('recalculate totals from items', function () {
    $billing = BillingRecord::factory()->create([
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
    ]);

    BillingRecordItem::factory()->create([
        'billing_record_id' => $billing->id,
        'amount' => 5000,
    ]);

    BillingRecordItem::factory()->create([
        'billing_record_id' => $billing->id,
        'amount' => 3000,
    ]);

    $result = $this->recalculate->handle($billing);

    expect((float) $result->subtotal_amount)->toBe(8000.0)
        ->and((float) $result->total_amount)->toBe(8000.0);
});

test('recalculate with discount', function () {
    $billing = BillingRecord::factory()->create([
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
    ]);

    BillingRecordItem::factory()->create([
        'billing_record_id' => $billing->id,
        'amount' => 10000,
    ]);

    $result = $this->recalculate->handle($billing, discountAmount: 1500);

    expect((float) $result->subtotal_amount)->toBe(10000.0)
        ->and((float) $result->discount_amount)->toBe(1500.0)
        ->and((float) $result->total_amount)->toBe(8500.0);
});

test('recalculate preserves posted payments', function () {
    $billing = BillingRecord::factory()->create([
        'subtotal_amount' => 10000,
        'discount_amount' => 0,
        'total_amount' => 10000,
        'amount_paid' => 3000,
        'balance_due' => 7000,
        'status' => BillingRecordStatus::PartiallyPaid,
    ]);

    BillingRecordItem::factory()->create([
        'billing_record_id' => $billing->id,
        'amount' => 10000,
    ]);

    $result = $this->recalculate->handle($billing);

    expect((float) $result->amount_paid)->toBe(3000.0)
        ->and((float) $result->balance_due)->toBe(7000.0)
        ->and($result->status)->toBe(BillingRecordStatus::PartiallyPaid);
});

test('recalculate voided record fails', function () {
    $billing = BillingRecord::factory()->voided()->create();

    $this->recalculate->handle($billing);
})->throws(ValidationException::class, 'voided billing record');

test('negative discount rejected', function () {
    $billing = BillingRecord::factory()->create();

    $this->recalculate->handle($billing, discountAmount: -100);
})->throws(ValidationException::class, 'Discount cannot be negative');

test('discount above subtotal rejected', function () {
    $billing = BillingRecord::factory()->create();

    BillingRecordItem::factory()->create([
        'billing_record_id' => $billing->id,
        'amount' => 1000,
    ]);

    $this->recalculate->handle($billing, discountAmount: 2000);
})->throws(ValidationException::class, 'Discount cannot exceed subtotal');
