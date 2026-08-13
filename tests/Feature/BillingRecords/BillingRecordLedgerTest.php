<?php

use App\Actions\BillingRecords\DispenseJobOrder;
use App\Actions\OpticalOrders\CancelOpticalOrder;
use App\Actions\OpticalOrders\CreateOpticalOrderFromQuotation;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->actingAs($this->staff);
});

test('cancellation releases committed inventory', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $quotation = Quotation::factory()->create(['total' => 5000]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation, confirmer: $this->staff);
    $jobOrder = $result['optical_order'];

    // Stock decremented after confirmation
    expect($variant->fresh()->stock_quantity)->toBe(9);

    app(CancelOpticalOrder::class)->handle($jobOrder);

    // Stock restored after cancellation
    expect($variant->fresh()->stock_quantity)->toBe(10);
});

test('cancellation voids billing when no posted payments', function () {
    $quotation = Quotation::factory()->create(['total' => 5000]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation, confirmer: $this->staff);
    $jobOrder = $result['optical_order'];

    app(CancelOpticalOrder::class)->handle($jobOrder);

    expect($result['billing_record']->fresh()->status)->toBe(BillingRecordStatus::Voided);
});

test('cancellation preserves billing when posted payments exist', function () {
    $quotation = Quotation::factory()->create(['total' => 10000]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 10000,
        'amount' => 10000,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation, confirmer: $this->staff, depositAmount: 3000);
    $jobOrder = $result['optical_order'];

    app(CancelOpticalOrder::class)->handle($jobOrder);

    $billing = $result['billing_record']->fresh();

    // Billing is NOT voided because there are posted payments
    expect($billing->status)->not->toBe(BillingRecordStatus::Voided)
        ->and((float) $billing->amount_paid)->toBe(3000.0);
});

test('dispensed order cannot be cancelled', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Dispensed,
        'supplier_invoice_number' => 'INV-001',
    ]);

    app(CancelOpticalOrder::class)->handle($jobOrder);
})->throws(ValidationException::class);

test('already cancelled order cannot be cancelled again', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::Cancelled,
    ]);

    app(CancelOpticalOrder::class)->handle($jobOrder);
})->throws(ValidationException::class);

test('paid billing is never overdue', function () {
    $billing = BillingRecord::factory()->create([
        'status' => BillingRecordStatus::Paid,
        'total_amount' => 5000,
        'amount_paid' => 5000,
        'balance_due' => 0,
        'payment_due_date' => today()->subDays(30),
    ]);

    expect($billing->isOverdue())->toBeFalse();
});

test('voided billing is never overdue', function () {
    $billing = BillingRecord::factory()->voided()->create([
        'payment_due_date' => today()->subDays(30),
    ]);

    expect($billing->isOverdue())->toBeFalse();
});

test('aggregate relationships consistent after cancellation', function () {
    $quotation = Quotation::factory()->create([
        'total' => 8000,
    ]);

    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 8000,
        'amount' => 8000,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation, confirmer: $this->staff);
    $jobOrder = $result['optical_order'];

    app(CancelOpticalOrder::class)->handle($jobOrder);

    expect($quotation->fresh()->jobOrder->id)->toBe($jobOrder->id)
        ->and($jobOrder->fresh()->billingRecord->id)->toBe($result['billing_record']->id)
        ->and($jobOrder->fresh()->status)->toBe(JobOrderStatus::Cancelled)
        ->and($result['billing_record']->fresh()->status)->toBe(BillingRecordStatus::Voided);
});

test('posted payment locks billing record charges', function () {
    $quotation = Quotation::factory()->create(['total' => 10000]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 10000,
        'amount' => 10000,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(quotation: $quotation, confirmer: $this->staff, depositAmount: 3000);
    $billing = $result['billing_record']->fresh();

    expect((float) $billing->amount_paid)->toBe(3000.0)
        ->and((float) $billing->balance_due)->toBe(7000.0)
        ->and($billing->status)->toBe(BillingRecordStatus::PartiallyPaid);
});

test('dispensing requires existing billing record', function () {
    $jobOrder = JobOrder::factory()->create([
        'status' => JobOrderStatus::ReadyForDispensing,
        'supplier_invoice_number' => 'INV-001',
    ]);

    $this->expectException(ValidationException::class);
    app(DispenseJobOrder::class)->handle($jobOrder, $this->staff);
});
