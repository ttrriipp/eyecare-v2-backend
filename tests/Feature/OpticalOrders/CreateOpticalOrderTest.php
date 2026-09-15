<?php

use App\Actions\OpticalOrders\CreateOpticalOrder;
use App\Enums\JobOrderStatus;
use App\Models\JobOrder;
use App\Models\LensCategory;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\NotificationStatusSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(NotificationStatusSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->action = app(CreateOpticalOrder::class);
});

test('staff creates a direct product order with no quotation', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'price' => 2500]);

    $result = $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [[
            'description' => 'Frame',
            'quantity' => 1,
            'unit_price' => 2500,
            'product_variant_id' => $variant->id,
        ]],
    );

    expect($result['job_order'])->toBeInstanceOf(JobOrder::class)
        ->and($result['job_order']->quotation_id)->toBeNull()
        ->and($result['job_order']->status)->toBe(JobOrderStatus::Queued)
        ->and($result['job_order']->items)->toHaveCount(1)
        ->and($variant->fresh()->stock_quantity)->toBe(9)
        ->and($result['billing_record']->patient_id)->toBe($patient->id)
        ->and((float) $result['billing_record']->total_amount)->toBe(2500.0);
});

test('immediate fulfillment completes and dispenses in one step', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 5, 'price' => 1000]);

    $result = $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [['description' => 'Readers', 'quantity' => 1, 'unit_price' => 1000, 'product_variant_id' => $variant->id]],
        fulfillmentMode: 'immediate',
    );

    expect($result['job_order']->status)->toBe(JobOrderStatus::Dispensed)
        ->and($result['job_order']->dispensed_at)->not->toBeNull()
        ->and($result['dispensing_event'])->not->toBeNull()
        ->and($result['dispensing_event']->recipient_name)->toBe($patient->full_name);
});

test('corrective items require a current prescription', function () {
    $patient = Patient::factory()->create();
    $lensCategory = LensCategory::factory()->withPrice()->create();

    $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [['description' => 'Single Vision Lens', 'quantity' => 1, 'unit_price' => 1200, 'lens_category_id' => $lensCategory->id]],
    );
})->throws(ValidationException::class, 'current prescription is required');

test('corrective items succeed with the patient\'s current prescription', function () {
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);
    $lensCategory = LensCategory::factory()->withPrice()->create(['price' => 1200]);

    $result = $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [['description' => 'Single Vision Lens', 'quantity' => 1, 'unit_price' => 1200, 'lens_category_id' => $lensCategory->id]],
        prescription: $prescription,
    );

    expect($result['job_order']->prescription_id)->toBe($prescription->id);
});

test('corrective items cannot use immediate fulfillment', function () {
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create(['patient_id' => $patient->id]);
    $lensCategory = LensCategory::factory()->withPrice()->create(['price' => 1200]);

    $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [['description' => 'Single Vision Lens', 'quantity' => 1, 'unit_price' => 1200, 'lens_category_id' => $lensCategory->id]],
        fulfillmentMode: 'immediate',
        prescription: $prescription,
    );
})->throws(ValidationException::class, 'cannot be completed immediately');

test('rejects insufficient stock', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 1]);

    $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [['description' => 'Frame', 'quantity' => 5, 'unit_price' => 500, 'product_variant_id' => $variant->id]],
    );
})->throws(ValidationException::class);

test('discount reduces the billing record total and balance due', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'price' => 2500]);
    $admin = User::factory()->admin()->create();

    $result = $this->action->handle(
        patient: $patient,
        creator: $admin,
        items: [[
            'description' => 'Frame',
            'quantity' => 1,
            'unit_price' => 2500,
            'product_variant_id' => $variant->id,
        ]],
        discountAmount: 500,
    );

    expect((float) $result['billing_record']->subtotal_amount)->toBe(2500.0)
        ->and((float) $result['billing_record']->discount_amount)->toBe(500.0)
        ->and((float) $result['billing_record']->total_amount)->toBe(2000.0)
        ->and((float) $result['billing_record']->balance_due)->toBe(2000.0);
});

test('senior citizen discount requires the patient to be at least 60 years old', function () {
    $patient = Patient::factory()->create([
        'date_of_birth' => today()->subYears(59),
    ]);
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'price' => 2500]);
    $admin = User::factory()->admin()->create();

    $this->action->handle(
        patient: $patient,
        creator: $admin,
        items: [[
            'description' => 'Frame',
            'quantity' => 1,
            'unit_price' => 2500,
            'product_variant_id' => $variant->id,
        ]],
        discountType: 'senior_citizen',
        discountAmount: 500,
    );
})->throws(ValidationException::class, 'Senior Citizen discount requires the patient to be at least 60 years old.');

test('an age-eligible patient must use the senior citizen discount', function () {
    $patient = Patient::factory()->create([
        'date_of_birth' => today()->subYears(60),
    ]);
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'price' => 2500]);
    $admin = User::factory()->admin()->create();

    $this->action->handle(
        patient: $patient,
        creator: $admin,
        items: [[
            'description' => 'Frame',
            'quantity' => 1,
            'unit_price' => 2500,
            'product_variant_id' => $variant->id,
        ]],
        discountType: 'other',
        discountAmount: 500,
    );
})->throws(ValidationException::class, 'Age-eligible patients must use the Senior Citizen discount.');

test('no discount leaves the billing record total unchanged', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'price' => 2500]);

    $result = $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [[
            'description' => 'Frame',
            'quantity' => 1,
            'unit_price' => 2500,
            'product_variant_id' => $variant->id,
        ]],
    );

    expect((float) $result['billing_record']->discount_amount)->toBe(0.0)
        ->and((float) $result['billing_record']->total_amount)->toBe(2500.0);
});

test('prepared fulfillment keeps the order queued', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'price' => 2500]);

    $result = $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [['description' => 'Frame', 'quantity' => 1, 'unit_price' => 2500, 'product_variant_id' => $variant->id]],
        fulfillmentMode: 'prepared',
    );

    expect($result['job_order']->status)->toBe(JobOrderStatus::Queued)
        ->and($result['job_order']->dispensed_at)->toBeNull()
        ->and($result['dispensing_event'])->toBeNull();
});

test('prepared fulfillment commits inventory', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'price' => 2500]);

    $result = $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [['description' => 'Frame', 'quantity' => 2, 'unit_price' => 2500, 'product_variant_id' => $variant->id]],
        fulfillmentMode: 'prepared',
    );

    expect($variant->fresh()->stock_quantity)->toBe(8)
        ->and($result['job_order']->items)->toHaveCount(1);
});

test('deposit records a payment on the billing record', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'price' => 5000]);

    $result = $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [['description' => 'Frame', 'quantity' => 1, 'unit_price' => 5000, 'product_variant_id' => $variant->id]],
        depositAmount: 2000,
        depositPaymentMethod: 'gcash',
        depositReference: 'GCASH-99',
    );

    $billing = $result['billing_record']->fresh();

    expect((float) $billing->amount_paid)->toBe(2000.0)
        ->and((float) $billing->balance_due)->toBe(3000.0)
        ->and($billing->payments)->toHaveCount(1)
        ->and($billing->payments->first()->payment_method)->toBe('gcash')
        ->and($billing->payments->first()->reference_number)->toBe('GCASH-99');
});

test('patient cannot create a direct optical order', function () {
    $patient = User::factory()->patient()->create();
    $patientRecord = Patient::factory()->create();

    $this->action->handle(
        patient: $patientRecord,
        creator: $patient,
        items: [['description' => 'Frame', 'quantity' => 1, 'unit_price' => 500]],
    );
})->throws(ValidationException::class);
