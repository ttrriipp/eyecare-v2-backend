<?php

use App\Actions\OpticalOrders\BuildOpticalOrder;
use App\Actions\OpticalOrders\CreateOpticalOrder;
use App\Enums\CommercialItemKind;
use App\Enums\JobOrderStatus;
use App\Enums\VatTreatment;
use App\Models\JobOrder;
use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Product;
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
    $patient = Patient::factory()->create(['date_of_birth' => today()->subYears(40)]);
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
        ->and((float) $result['billing_record']->total_amount)->toBe(2500.0)
        ->and($result['billing_record']->vat_calculation_version)->toBe(1)
        ->and((float) $result['billing_record']->vatable_sales_amount)->toBe(2232.14)
        ->and((float) $result['billing_record']->vat_amount)->toBe(267.86);
});

test('catalog VAT treatment is used when a staff member creates the bill', function () {
    $patient = Patient::factory()->create(['date_of_birth' => today()->subYears(40)]);
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'price' => 2500]);
    $variant->product->update(['vat_treatment' => VatTreatment::Exempt->value]);

    $result = $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [[
            'description' => 'Frame',
            'quantity' => 1,
            'unit_price' => 2500,
            'product_variant_id' => $variant->id,
            'vat_treatment' => VatTreatment::Vatable->value,
        ]],
    );

    $line = $result['billing_record']->items()->firstOrFail();

    expect($line->vat_treatment)->toBe(VatTreatment::Exempt)
        ->and((float) $result['billing_record']->vatable_sales_amount)->toBe(0.0)
        ->and((float) $result['billing_record']->vat_amount)->toBe(0.0)
        ->and((float) $result['billing_record']->vat_exempt_sales_amount)->toBe(2500.0)
        ->and((float) $result['billing_record']->total_amount)->toBe(2500.0);
});

test('immediate fulfillment completes and dispenses in one step', function () {
    $patient = Patient::factory()->create(['date_of_birth' => today()->subYears(40)]);
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
    $patient = Patient::factory()->create(['date_of_birth' => today()->subYears(40)]);
    $lensCategory = LensCategory::factory()->withPrice()->create();

    $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [['description' => 'Single Vision Lens', 'quantity' => 1, 'unit_price' => 1200, 'lens_category_id' => $lensCategory->id]],
    );
})->throws(ValidationException::class, 'current prescription is required');

test('corrective items succeed with the patient\'s current prescription', function () {
    $patient = Patient::factory()->create(['date_of_birth' => today()->subYears(40)]);
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
    $patient = Patient::factory()->create(['date_of_birth' => today()->subYears(40)]);
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
    $patient = Patient::factory()->create(['date_of_birth' => today()->subYears(40)]);
    $variant = ProductVariant::factory()->create(['stock_quantity' => 1]);

    $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [['description' => 'Frame', 'quantity' => 5, 'unit_price' => 500, 'product_variant_id' => $variant->id]],
    );
})->throws(ValidationException::class);

test('discount reduces the billing record total and balance due', function () {
    $patient = Patient::factory()->create([
        'date_of_birth' => today()->subYears(40),
    ]);
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

test('senior citizen discount requires verified entitlement and exclusive use', function () {
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
        discountType: 'senior_citizen',
        discountEligibilityVerified: false,
    );
})->throws(ValidationException::class, 'Verify the patient’s entitlement and exclusive use');

test('no discount leaves the billing record total unchanged', function () {
    $patient = Patient::factory()->create(['date_of_birth' => today()->subYears(40)]);
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
    $patient = Patient::factory()->create(['date_of_birth' => today()->subYears(40)]);
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
    $patient = Patient::factory()->create(['date_of_birth' => today()->subYears(40)]);
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
    $patient = Patient::factory()->create(['date_of_birth' => today()->subYears(40)]);
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
    $patientRecord = Patient::factory()->create(['date_of_birth' => today()->subYears(40)]);

    $this->action->handle(
        patient: $patientRecord,
        creator: $patient,
        items: [['description' => 'Frame', 'quantity' => 1, 'unit_price' => 500]],
    );
})->throws(ValidationException::class);

test('supplier exclusivity rejects catalog products in external orders without writes', function (string $productType, bool $includeCustomItem) {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()
        ->for(Product::factory()->state(['product_type' => $productType]))
        ->create(['stock_quantity' => 10]);
    $items = [[
        'item_kind' => 'custom',
        'description' => 'Catalog product labelled custom',
        'quantity' => 1,
        'unit_price' => 500,
        'product_variant_id' => $variant->id,
    ]];

    if ($includeCustomItem) {
        $items[] = ['description' => 'External accessory', 'quantity' => 1, 'unit_price' => 100];
    }

    expect(fn () => $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: $items,
        usesExternalSupplier: true,
    ))->toThrow(ValidationException::class, 'External supplier orders cannot include catalog products.');

    $this->assertDatabaseEmpty('job_orders');
    $this->assertDatabaseEmpty('job_order_items');
    $this->assertDatabaseEmpty('billing_records');
    $this->assertDatabaseEmpty('inventory_movements');
    expect($variant->fresh()->stock_quantity)->toBe(10);
})->with([
    'frame' => ['frame', false],
    'accessory' => ['accessory', false],
    'contact lenses' => ['contact_lens', false],
    'mixed catalog and external' => ['accessory', true],
]);

test('supplier exclusivity rejects reference-free internal products without writes', function (array $metadata) {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    expect(fn () => $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [
            ['description' => 'Catalog frame', 'quantity' => 1, 'unit_price' => 500, 'product_variant_id' => $variant->id],
            [...$metadata, 'description' => 'Manual product', 'quantity' => 1, 'unit_price' => 100],
        ],
    ))->toThrow(ValidationException::class, 'Internal orders cannot include external products.');

    $this->assertDatabaseEmpty('job_orders');
    $this->assertDatabaseEmpty('job_order_items');
    $this->assertDatabaseEmpty('billing_records');
    $this->assertDatabaseEmpty('inventory_movements');
    expect($variant->fresh()->stock_quantity)->toBe(10);
})->with([
    'manual product' => [[]],
    'catalog label without reference' => [['item_kind' => 'catalog']],
    'lens label without reference' => [['item_kind' => 'lens']],
    'custom label' => [['item_kind' => 'custom']],
    'forged patient snapshot' => [['item_snapshot' => ['source' => 'patient']]],
]);

test('supplier exclusivity allows external frame and accessory with lens package and option', function () {
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->for($patient)->create();
    $lensCategory = LensCategory::factory()->withPrice()->create(['price' => 1200]);
    $lensOption = LensOption::factory()->create(['price' => 300]);

    $result = $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [
            ['description' => 'External frame', 'quantity' => 1, 'unit_price' => 800, 'eyewear_role' => 'frame'],
            ['description' => 'External accessory', 'quantity' => 2, 'unit_price' => 50, 'eyewear_role' => 'other'],
            ['description' => 'Lens package', 'quantity' => 1, 'unit_price' => 1200, 'lens_category_id' => $lensCategory->id],
            ['item_kind' => 'lens_option', 'description' => 'Lens option', 'quantity' => 1, 'unit_price' => 300, 'lens_option_id' => $lensOption->id],
        ],
        usesExternalSupplier: true,
        prescription: $prescription,
    );

    expect($result['job_order']->uses_external_supplier)->toBeTrue()
        ->and($result['job_order']->status)->toBe(JobOrderStatus::Queued)
        ->and($result['job_order']->prescription_id)->toBe($prescription->id)
        ->and($result['job_order']->items)->toHaveCount(4);
    expect($result['job_order']->items->firstWhere('description', 'External frame')->item_snapshot)
        ->toBe(['eyewear_role' => 'frame']);
    expect($result['job_order']->items->firstWhere('description', 'External accessory')->item_snapshot)
        ->toBe(['eyewear_role' => 'other']);
    expect((float) $result['billing_record']->total_amount)->toBe(2400.0);
    $this->assertDatabaseEmpty('inventory_movements');
});

test('supplier exclusivity keeps unmarked manual products external with no patient snapshot', function () {
    $patient = Patient::factory()->create();

    $result = $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [['description' => 'External accessory', 'quantity' => 1, 'unit_price' => 100, 'item_snapshot' => ['source' => 'patient']]],
        usesExternalSupplier: true,
    );

    expect($result['job_order']->items->sole()->item_kind)->toBe(CommercialItemKind::CustomProduct)
        ->and($result['job_order']->items->sole()->item_snapshot)->toBeNull();
    expect((float) $result['billing_record']->total_amount)->toBe(100.0);
});

test('supplier exclusivity preserves a patient frame with internal lenses', function () {
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->for($patient)->create();
    $lensCategory = LensCategory::factory()->withPrice()->create(['price' => 1200]);
    $lensOption = LensOption::factory()->create(['price' => 300]);

    $result = $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [
            ['description' => 'Patient frame', 'quantity' => 1, 'unit_price' => 0, 'is_patient_supplied' => true, 'eyewear_role' => 'frame'],
            ['description' => 'Lens package', 'quantity' => 1, 'unit_price' => 1200, 'lens_category_id' => $lensCategory->id],
            ['item_kind' => 'lens_option', 'description' => 'Lens option', 'quantity' => 1, 'unit_price' => 300, 'lens_option_id' => $lensOption->id],
        ],
        prescription: $prescription,
    );

    $frame = $result['job_order']->items->firstWhere('description', 'Patient frame');

    expect($frame->item_kind)->toBe(CommercialItemKind::CustomProduct)
        ->and($frame->item_snapshot)->toBe(['source' => 'patient', 'eyewear_role' => 'frame'])
        ->and($frame->product_variant_id)->toBeNull();
    expect($result['job_order']->uses_external_supplier)->toBeFalse();
    expect((float) $result['billing_record']->total_amount)->toBe(1500.0);
    $this->assertDatabaseEmpty('inventory_movements');
});

test('supplier exclusivity rejects immediate external fulfillment without writes', function () {
    $patient = Patient::factory()->create();

    expect(fn () => $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [['description' => 'External accessory', 'quantity' => 1, 'unit_price' => 100]],
        fulfillmentMode: 'immediate',
        usesExternalSupplier: true,
    ))->toThrow(ValidationException::class, 'External supplier orders must use prepared fulfillment.');

    $this->assertDatabaseEmpty('job_orders');
    $this->assertDatabaseEmpty('job_order_items');
    $this->assertDatabaseEmpty('billing_records');
    $this->assertDatabaseEmpty('inventory_movements');
    $this->assertDatabaseEmpty('dispensing_events');
});

test('supplier exclusivity validates patient and eyewear metadata without writes', function (array $metadata) {
    $patient = Patient::factory()->create();

    expect(fn () => $this->action->handle(
        patient: $patient,
        creator: $this->staff,
        items: [[...$metadata, 'description' => 'External frame', 'quantity' => 1, 'unit_price' => 100]],
        usesExternalSupplier: true,
    ))->toThrow(ValidationException::class);

    $this->assertDatabaseEmpty('job_orders');
    $this->assertDatabaseEmpty('billing_records');
    $this->assertDatabaseEmpty('inventory_movements');
})->with([
    'invalid patient flag' => [['is_patient_supplied' => 'patient']],
    'invalid eyewear role' => [['eyewear_role' => 'accessory']],
]);

test('supplier exclusivity core rejects invalid patient exceptions and misleading kinds before writes', function (array $itemOverrides, bool $hasLensPackage, bool $hasPrescription, bool $usesExternalSupplier) {
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->for($patient)->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $lensCategory = LensCategory::factory()->withPrice()->create();
    $item = [
        'description' => 'Patient frame',
        'quantity' => 1,
        'unit_price' => '0.00',
        'amount' => '0.00',
        'item_kind' => CommercialItemKind::CustomProduct,
        'item_snapshot' => ['source' => 'patient'],
        ...$itemOverrides,
    ];

    if (array_key_exists('product_variant_id', $itemOverrides)) {
        $item['product_variant_id'] = $variant->id;
    }

    $items = collect([$item]);

    if ($hasLensPackage) {
        $items->push([
            'description' => 'Lens package',
            'quantity' => 1,
            'unit_price' => '1200.00',
            'amount' => '1200.00',
            'lens_category_id' => $lensCategory->id,
            'item_kind' => CommercialItemKind::LensPackage,
        ]);
    }

    expect(fn () => app(BuildOpticalOrder::class)->handle(
        patientId: $patient->id,
        encounterId: null,
        prescriptionId: $hasPrescription ? $prescription->id : null,
        fulfillmentMode: 'prepared',
        usesExternalSupplier: $usesExternalSupplier,
        items: $items,
        actorId: $this->staff->id,
        notifyPatient: false,
    ))->toThrow(ValidationException::class);

    $this->assertDatabaseEmpty('job_orders');
    $this->assertDatabaseEmpty('job_order_items');
    $this->assertDatabaseEmpty('billing_records');
    $this->assertDatabaseEmpty('inventory_movements');
    expect($variant->fresh()->stock_quantity)->toBe(10);
})->with([
    'patient frame needs lenses' => [[], false, true, false],
    'patient frame needs prescription' => [[], true, false, false],
    'patient frame quantity is one' => [['quantity' => 2], true, true, false],
    'patient frame must be custom product' => [['item_kind' => CommercialItemKind::Accessory], true, true, false],
    'patient frame needs patient source' => [['item_snapshot' => null], true, true, false],
    'lens kind cannot hide missing references' => [['item_kind' => CommercialItemKind::LensPackage, 'item_snapshot' => null], false, true, false],
    'patient marker cannot hide external catalog reference' => [['product_variant_id' => true], true, true, true],
]);

test('supplier exclusivity core requires a usable patient prescription for patient frames', function (string $scenario) {
    $patient = Patient::factory()->create();
    $prescription = Prescription::factory()->create([
        'patient_id' => $scenario === 'other patient' ? Patient::factory()->create()->id : $patient->id,
        'cancelled_at' => $scenario === 'cancelled' ? now() : null,
    ]);
    $lensCategory = LensCategory::factory()->withPrice(1200)->create();

    if ($scenario === 'superseded') {
        Prescription::factory()->create([
            'patient_id' => $patient->id,
            'previous_prescription_id' => $prescription->id,
        ]);
    }

    expect(fn () => app(BuildOpticalOrder::class)->handle(
        patientId: $patient->id,
        encounterId: null,
        prescriptionId: $prescription->id,
        fulfillmentMode: 'prepared',
        usesExternalSupplier: false,
        items: collect([
            [
                'description' => 'Patient frame',
                'quantity' => 1,
                'unit_price' => '0.00',
                'amount' => '0.00',
                'item_kind' => CommercialItemKind::CustomProduct,
                'item_snapshot' => ['source' => 'patient', 'eyewear_role' => 'frame'],
            ],
            [
                'description' => 'Lens package',
                'quantity' => 1,
                'unit_price' => '1200.00',
                'amount' => '1200.00',
                'item_kind' => CommercialItemKind::LensPackage,
                'lens_category_id' => $lensCategory->id,
            ],
        ]),
        actorId: $this->staff->id,
        notifyPatient: false,
    ))->toThrow(ValidationException::class, 'patient-supplied frame');

    $this->assertDatabaseEmpty('job_orders');
    $this->assertDatabaseEmpty('inventory_movements');
})->with(['other patient', 'cancelled', 'superseded']);
