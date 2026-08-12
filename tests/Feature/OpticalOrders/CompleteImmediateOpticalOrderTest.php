<?php

use App\Actions\OpticalOrders\CreateOpticalOrderFromQuotation;
use App\Enums\BillingRecordStatus;
use App\Models\DispensingEvent;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->actingAs($this->staff);
});

test('immediate service-only completes without dispensing event', function () {
    $quotation = Quotation::factory()->create(['total' => 1500]);
    $quotation->items()->create([
        'description' => 'Eye Exam',
        'quantity' => 1,
        'unit_price' => 1500,
        'amount' => 1500,
        'item_kind' => 'service',
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
        fulfillmentMode: 'immediate',
    );

    // Service-only quotations create no job order
    expect($result['optical_order'])->toBeNull()
        ->and($result['billing_record'])->not->toBeNull()
        ->and($result['billing_record']->status)->toBe(BillingRecordStatus::Unpaid);
});

test('immediate product creates dispensing event', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $quotation = Quotation::factory()->create(['total' => 5000]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
        fulfillmentMode: 'immediate',
    );

    $dispensingEvent = $result['optical_order']->dispensingEvents()->latest()->first();
    expect($dispensingEvent)->toBeInstanceOf(DispensingEvent::class)
        ->and($dispensingEvent->dispensed_by)->toBe($this->staff->id);
});

test('immediate product commits inventory', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $quotation = Quotation::factory()->create(['total' => 5000]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 2,
        'unit_price' => 2500,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
    ]);

    app(CreateOpticalOrderFromQuotation::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
        fulfillmentMode: 'immediate',
    );

    expect($variant->fresh()->stock_quantity)->toBe(8);
});

test('immediate with deposit records payment', function () {
    $quotation = Quotation::factory()->create(['total' => 5000]);
    $quotation->items()->create([
        'description' => 'Service',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'item_kind' => 'service',
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
        fulfillmentMode: 'immediate',
        depositAmount: 3000,
        depositPaymentMethod: 'gcash',
    );

    $billing = $result['billing_record']->fresh();

    expect((float) $billing->amount_paid)->toBe(3000.0)
        ->and((float) $billing->balance_due)->toBe(2000.0)
        ->and($billing->status)->toBe(BillingRecordStatus::PartiallyPaid);
});

test('immediate mixed order commits inventory and creates dispensing', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $quotation = Quotation::factory()->create(['total' => 6000]);
    $quotation->items()->createMany([
        ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 5000, 'amount' => 5000, 'product_variant_id' => $variant->id],
        ['description' => 'Fitting', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000],
    ]);

    $result = app(CreateOpticalOrderFromQuotation::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
        fulfillmentMode: 'immediate',
    );

    expect($variant->fresh()->stock_quantity)->toBe(9)
        ->and($result['optical_order']->dispensingEvents()->latest()->first())->toBeInstanceOf(DispensingEvent::class);
});
