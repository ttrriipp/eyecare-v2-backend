<?php

use App\Actions\Quotations\CreateQuotation;
use App\Enums\AuditEvent;
use App\Enums\QuotationStatus;
use App\Models\AuditLog;
use App\Models\Encounter;
use App\Models\LensCategory;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('staff creates a draft quotation with direct items from an encounter prescription', function () {
    $staff = User::factory()->admin()->create(); // Admin needed for discount
    $encounter = Encounter::factory()->inProgress()->create();
    $prescription = Prescription::factory()->linkedToEncounter($encounter)->create();
    $variant = ProductVariant::factory()->create(['price' => 5000]);
    $lensCategory = LensCategory::factory()->create(['price' => 1500]);

    $quotation = app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $staff,
        data: [
            'valid_until' => now()->addWeek()->toDateString(),
            'discount_amount' => 500,
            'notes' => 'Estimate discussed with the patient.',
            'internal_notes' => 'Hold the selected frame.',
            'items' => [
                [
                    'description' => 'Selected frame',
                    'quantity' => 1,
                    'unit_price' => 5000,
                    'product_variant_id' => $variant->id,
                ],
                [
                    'description' => 'Single vision lens',
                    'quantity' => 1,
                    'unit_price' => 1500,
                    'lens_category_id' => $lensCategory->id,
                ],
            ],
        ],
        encounter: $encounter,
    );

    // Direct totals on quotation (no revision)
    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->patient_id)->toBe($encounter->patient_id)
        ->and($quotation->encounter_id)->toBe($encounter->id)
        ->and($quotation->prescription_id)->toBe($prescription->id)
        ->and((float) $quotation->subtotal)->toBe(6500.0)
        ->and((float) $quotation->discount_amount)->toBe(500.0)
        ->and((float) $quotation->total)->toBe(6000.0);

    // Direct items on quotation
    expect($quotation->items)->toHaveCount(2)
        ->and((float) $quotation->items->first()->amount)->toBe(5000.0)
        ->and((float) $quotation->items->last()->amount)->toBe(1500.0);

    // Audit log created
    expect(AuditLog::query()
        ->where('subject_type', $quotation->getMorphClass())
        ->where('subject_id', $quotation->id)
        ->where('actor_id', $staff->id)
        ->where('action', AuditEvent::QuotationCreated->value)
        ->exists())->toBeTrue();
});

test('an optometrist can create a quotation for a completed encounter', function () {
    $optometrist = User::factory()->optometrist()->create();
    $encounter = Encounter::factory()->completed()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $quotation = app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $optometrist,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'description' => 'Custom fitting service',
                'quantity' => 1,
                'unit_price' => 750,
            ]],
        ],
        encounter: $encounter,
    );

    expect((float) $quotation->total)->toBe(750.0)
        ->and($quotation->items)->toHaveCount(1);
});

test('quotation creation requires an authorized clinic user', function () {
    $patientUser = User::factory()->patient()->create();
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $patientUser,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'description' => 'Frame',
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
        ],
        encounter: $encounter,
    );
})->throws(ValidationException::class, 'Only clinic staff can create a quotation.');

test('quotation creation requires an eligible encounter status', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $staff,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'description' => 'Frame',
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
        ],
        encounter: $encounter,
    );
})->throws(ValidationException::class);

test('corrective eyewear requires a current prescription', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->inProgress()->create();
    $lensCategory = LensCategory::factory()->create(['price' => 1000]);

    app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $staff,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'description' => 'Lens',
                'quantity' => 1,
                'unit_price' => 1000,
                'lens_category_id' => $lensCategory->id,
            ]],
        ],
        encounter: $encounter,
    );
})->throws(ValidationException::class);

test('only one quotation can be created for an encounter', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();
    $data = [
        'discount_amount' => 0,
        'items' => [[
            'description' => 'Frame',
            'quantity' => 1,
            'unit_price' => 1000,
        ]],
    ];

    app(CreateQuotation::class)->handle($encounter->patient, $staff, $data, $encounter);
    app(CreateQuotation::class)->handle($encounter->patient, $staff, $data, $encounter);
})->throws(ValidationException::class, 'This encounter already has a quotation.');

test('quotation creation rejects invalid item sources and discounts without partial records', function (
    array $quotationOverrides,
    array $itemOverrides,
) {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    try {
        $data = [
            'discount_amount' => 0,
            'items' => [[
                'description' => 'Frame',
                'quantity' => 1,
                'unit_price' => 1000,
                ...$itemOverrides,
            ]],
        ];

        app(CreateQuotation::class)->handle(
            patient: $encounter->patient,
            creator: $staff,
            data: array_replace($data, $quotationOverrides),
            encounter: $encounter,
        );
    } catch (ValidationException) {
        expect(Quotation::query()->where('encounter_id', $encounter->id)->exists())->toBeFalse();

        return;
    }

    $this->fail('Expected quotation validation to fail.');
})->with([
    'discount exceeds subtotal' => [['discount_amount' => 1001], []],
    'missing line items' => [['items' => []], []],
    'invalid quantity' => [[], ['quantity' => 0]],
    'invalid product variant' => [[], ['product_variant_id' => 999999]],
]);

test('a quotation item cannot reference both a product variant and lens category', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();
    $variant = ProductVariant::factory()->create(['price' => 5000]);
    $lensCategory = LensCategory::factory()->create(['price' => 1000]);

    app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $staff,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'description' => 'Invalid combined source',
                'quantity' => 1,
                'unit_price' => 1000,
                'product_variant_id' => $variant->id,
                'lens_category_id' => $lensCategory->id,
            ]],
        ],
        encounter: $encounter,
    );
})->throws(ValidationException::class);

test('non-corrective sale does not require an encounter', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['price' => 5000]);

    $quotation = app(CreateQuotation::class)->handle(
        patient: $patient,
        creator: $staff,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'description' => 'Frame',
                'quantity' => 1,
                'unit_price' => 5000,
                'product_variant_id' => $variant->id,
            ]],
        ],
    );

    expect($quotation->encounter_id)->toBeNull()
        ->and($quotation->prescription_id)->toBeNull()
        ->and((float) $quotation->total)->toBe(5000.0)
        ->and($quotation->items)->toHaveCount(1);
});

test('mixed product and service items are preserved', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['price' => 4500]);

    $quotation = app(CreateQuotation::class)->handle(
        patient: $patient,
        creator: $staff,
        data: [
            'discount_amount' => 0,
            'items' => [
                ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 4500, 'product_variant_id' => $variant->id],
                ['description' => 'Anti-reflective coating', 'quantity' => 1, 'unit_price' => 1000],
                ['description' => 'Fitting service', 'quantity' => 1, 'unit_price' => 500],
            ],
        ],
    );

    expect($quotation->items)->toHaveCount(3)
        ->and((float) $quotation->total)->toBe(6000.0);
});

test('a quotation item can reference the service catalog', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $service = Service::factory()->create(['price' => 800]);

    $quotation = app(CreateQuotation::class)->handle(
        patient: $patient,
        creator: $staff,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'description' => $service->name,
                'quantity' => 1,
                'unit_price' => 800,
                'service_id' => $service->id,
            ]],
        ],
    );

    expect($quotation->items->first()->service_id)->toBe($service->id);
});

test('catalog description and price are derived instead of trusting submitted values', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $product = Product::factory()->create([
        'name' => 'Aster Frame',
        'product_type' => 'frame',
    ]);
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'name' => 'Matte Black',
        'price' => 2450,
    ]);

    $quotation = app(CreateQuotation::class)->handle(
        patient: $patient,
        creator: $staff,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'item_kind' => 'catalog',
                'description' => 'Tampered description',
                'quantity' => 1,
                'unit_price' => 1,
                'product_variant_id' => $variant->id,
            ]],
        ],
    );

    $item = $quotation->items->first();

    expect($item->description)->toBe('Aster Frame — Matte Black')
        ->and((float) $item->unit_price)->toBe(2450.0)
        ->and((float) $item->amount)->toBe(2450.0)
        ->and((float) $quotation->total)->toBe(2450.0);
});

test('an inactive service cannot be referenced on a new quotation item', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $service = Service::factory()->inactive()->create();

    app(CreateQuotation::class)->handle(
        patient: $patient,
        creator: $staff,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'description' => $service->name,
                'quantity' => 1,
                'unit_price' => 800,
                'service_id' => $service->id,
            ]],
        ],
    );
})->throws(ValidationException::class);

test('a quotation item cannot reference both a service and a catalog item', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();
    $service = Service::factory()->create();

    app(CreateQuotation::class)->handle(
        patient: $patient,
        creator: $staff,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'description' => 'Invalid combined source',
                'quantity' => 1,
                'unit_price' => 1000,
                'product_variant_id' => $variant->id,
                'service_id' => $service->id,
            ]],
        ],
    );
})->throws(ValidationException::class);

test('a standalone existing prescription allows corrective eyewear with no new encounter', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->completed()->create();
    $prescription = Prescription::factory()->linkedToEncounter($encounter)->create();
    $lensCategory = LensCategory::factory()->create(['price' => 1500]);

    $quotation = app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $staff,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'description' => 'Single vision lens',
                'quantity' => 1,
                'unit_price' => 1500,
                'lens_category_id' => $lensCategory->id,
            ]],
        ],
        prescription: $prescription,
    );

    expect($quotation->encounter_id)->toBeNull()
        ->and($quotation->prescription_id)->toBe($prescription->id);
});

test('a standalone prescription must belong to the patient', function () {
    $staff = User::factory()->staff()->create();
    $patient = Patient::factory()->create();
    $otherEncounter = Encounter::factory()->completed()->create();
    $otherPrescription = Prescription::factory()->linkedToEncounter($otherEncounter)->create();
    $lensCategory = LensCategory::factory()->create(['price' => 1500]);

    app(CreateQuotation::class)->handle(
        patient: $patient,
        creator: $staff,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'description' => 'Single vision lens',
                'quantity' => 1,
                'unit_price' => 1500,
                'lens_category_id' => $lensCategory->id,
            ]],
        ],
        prescription: $otherPrescription,
    );
})->throws(ValidationException::class);

test('a superseded standalone prescription is rejected', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->completed()->create();
    $original = Prescription::factory()->linkedToEncounter($encounter)->create();
    Prescription::factory()->linkedToEncounter($encounter)->create([
        'previous_prescription_id' => $original->id,
    ]);
    $lensCategory = LensCategory::factory()->create(['price' => 1500]);

    app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $staff,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'description' => 'Single vision lens',
                'quantity' => 1,
                'unit_price' => 1500,
                'lens_category_id' => $lensCategory->id,
            ]],
        ],
        prescription: $original,
    );
})->throws(ValidationException::class);

test('an explicit prescription takes priority over the encounter\'s own resolved prescription', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    // A second, unrelated current prescription for the same patient, from a
    // different (older) encounter.
    $otherEncounter = Encounter::factory()->completed()->create(['patient_id' => $encounter->patient_id]);
    $explicitPrescription = Prescription::factory()->linkedToEncounter($otherEncounter)->create();
    $lensCategory = LensCategory::factory()->create(['price' => 1500]);

    $quotation = app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $staff,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'description' => 'Single vision lens',
                'quantity' => 1,
                'unit_price' => 1500,
                'lens_category_id' => $lensCategory->id,
            ]],
        ],
        encounter: $encounter,
        prescription: $explicitPrescription,
    );

    expect($quotation->prescription_id)->toBe($explicitPrescription->id);
});
