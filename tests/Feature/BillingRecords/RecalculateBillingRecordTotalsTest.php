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

test('recalculates and persists an inclusive VAT breakdown for new records', function () {
    $billing = BillingRecord::factory()->create([
        'vat_calculation_version' => 1,
        'discount_type' => 'none',
    ]);

    BillingRecordItem::factory()->create([
        'billing_record_id' => $billing->id,
        'amount' => 1120,
        'vat_treatment' => 'vatable',
        'statutory_discount_eligible' => false,
    ]);

    $result = $this->recalculate->handle($billing);

    expect((float) $result->vatable_sales_amount)->toBe(1000.0)
        ->and((float) $result->vat_amount)->toBe(120.0)
        ->and((float) $result->vat_exempt_sales_amount)->toBe(0.0)
        ->and((float) $result->zero_rated_sales_amount)->toBe(0.0)
        ->and((float) $result->vat_exemption_amount)->toBe(0.0)
        ->and((float) $result->total_amount)->toBe(1120.0);
});

test('applies the statutory VAT exemption and 20 percent discount to qualifying lines', function () {
    $billing = BillingRecord::factory()->create([
        'vat_calculation_version' => 1,
        'discount_type' => 'pwd',
        'discount_eligibility_verified' => true,
    ]);

    BillingRecordItem::factory()->create([
        'billing_record_id' => $billing->id,
        'amount' => 1120,
        'vat_treatment' => 'vatable',
        'statutory_discount_eligible' => true,
    ]);

    $result = $this->recalculate->handle($billing);

    expect((float) $result->vatable_sales_amount)->toBe(0.0)
        ->and((float) $result->vat_amount)->toBe(0.0)
        ->and((float) $result->vat_exempt_sales_amount)->toBe(1000.0)
        ->and((float) $result->vat_exemption_amount)->toBe(120.0)
        ->and((float) $result->discount_amount)->toBe(200.0)
        ->and((float) $result->total_amount)->toBe(800.0);
});

test('requires verified entitlement and an eligible line for statutory discounts', function () {
    $billing = BillingRecord::factory()->create([
        'vat_calculation_version' => 1,
        'discount_type' => 'senior_citizen',
        'discount_eligibility_verified' => false,
    ]);

    BillingRecordItem::factory()->create([
        'billing_record_id' => $billing->id,
        'amount' => 1120,
        'vat_treatment' => 'vatable',
        'statutory_discount_eligible' => true,
    ]);

    $this->recalculate->handle($billing);
})->throws(ValidationException::class, 'Verify the patient’s entitlement and exclusive use');

test('rejects statutory discounts when no line is eligible', function () {
    $billing = BillingRecord::factory()->create([
        'vat_calculation_version' => 1,
        'discount_type' => 'senior_citizen',
        'discount_eligibility_verified' => true,
    ]);

    BillingRecordItem::factory()->create([
        'billing_record_id' => $billing->id,
        'amount' => 1120,
        'vat_treatment' => 'vatable',
        'statutory_discount_eligible' => false,
    ]);

    $this->recalculate->handle($billing);
})->throws(ValidationException::class, 'At least one legally qualifying line');

test('keeps existing records on the legacy calculation when recalculated', function () {
    $billing = BillingRecord::factory()->create([
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
        'vat_calculation_version' => null,
    ]);

    BillingRecordItem::factory()->create([
        'billing_record_id' => $billing->id,
        'amount' => 1120,
    ]);

    $result = $this->recalculate->handle($billing);

    expect((float) $result->total_amount)->toBe(1120.0)
        ->and((float) $result->vatable_sales_amount)->toBe(0.0)
        ->and((float) $result->vat_amount)->toBe(0.0);
});

test('a full discount marks the bill as paid with no balance due', function () {
    $billing = BillingRecord::factory()->create([
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
        'balance_due' => 0,
        'amount_paid' => 0,
        'status' => BillingRecordStatus::Unpaid,
    ]);

    BillingRecordItem::factory()->create([
        'billing_record_id' => $billing->id,
        'amount' => 800,
    ]);

    $result = $this->recalculate->handle($billing, discountAmount: 800);

    expect((float) $result->total_amount)->toBe(0.0)
        ->and((float) $result->balance_due)->toBe(0.0)
        ->and($result->status)->toBe(BillingRecordStatus::Paid);
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

test('recalculate cancelled record fails', function () {
    $billing = BillingRecord::factory()->cancelled()->create();

    $this->recalculate->handle($billing);
})->throws(ValidationException::class, 'Cannot modify a cancelled billing record');

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
