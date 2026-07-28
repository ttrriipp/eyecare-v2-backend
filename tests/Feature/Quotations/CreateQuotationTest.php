<?php

use App\Actions\Quotations\CreateQuotation;
use App\Enums\AuditEvent;
use App\Enums\QuotationStatus;
use App\Models\AuditLog;
use App\Models\Encounter;
use App\Models\LensCategory;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('staff creates a draft quotation with a calculated first revision from an encounter prescription', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->inProgress()->create();
    $prescription = Prescription::factory()->linkedToEncounter($encounter)->create();
    $variant = ProductVariant::factory()->create();
    $lensCategory = LensCategory::factory()->create(['price' => 1500]);

    $quotation = app(CreateQuotation::class)->handle(
        encounter: $encounter,
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
                    'quantity' => 2,
                    'unit_price' => 1500,
                    'lens_category_id' => $lensCategory->id,
                ],
            ],
        ],
    );

    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->patient_id)->toBe($encounter->patient_id)
        ->and($quotation->encounter_id)->toBe($encounter->id)
        ->and($quotation->prescription_id)->toBe($prescription->id)
        ->and($quotation->latestRevision->revision_number)->toBe(1)
        ->and($quotation->latestRevision->subtotal)->toBe('8000.00')
        ->and($quotation->latestRevision->discount_amount)->toBe('500.00')
        ->and($quotation->latestRevision->total)->toBe('7500.00')
        ->and($quotation->latestRevision->items)->toHaveCount(2)
        ->and($quotation->latestRevision->items->first()->amount)->toBe('5000.00')
        ->and($quotation->latestRevision->items->last()->amount)->toBe('3000.00');

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
        encounter: $encounter,
        creator: $optometrist,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'description' => 'Custom fitting service',
                'quantity' => 1,
                'unit_price' => 750,
            ]],
        ],
    );

    expect($quotation->latestRevision->total)->toBe('750.00');
});

test('quotation creation requires an authorized clinic user', function () {
    $patientUser = User::factory()->patient()->create();
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    app(CreateQuotation::class)->handle(
        encounter: $encounter,
        creator: $patientUser,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'description' => 'Frame',
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
        ],
    );
})->throws(ValidationException::class, 'Only clinic staff can create a quotation.');

test('quotation creation requires an eligible encounter status', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    app(CreateQuotation::class)->handle(
        encounter: $encounter,
        creator: $staff,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'description' => 'Frame',
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
        ],
    );
})->throws(ValidationException::class);

test('quotation creation requires a current prescription', function () {
    $staff = User::factory()->staff()->create();
    $encounter = Encounter::factory()->inProgress()->create();

    app(CreateQuotation::class)->handle(
        encounter: $encounter,
        creator: $staff,
        data: [
            'discount_amount' => 0,
            'items' => [[
                'description' => 'Frame',
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
        ],
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

    app(CreateQuotation::class)->handle($encounter, $staff, $data);
    app(CreateQuotation::class)->handle($encounter, $staff, $data);
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
            encounter: $encounter,
            creator: $staff,
            data: array_replace($data, $quotationOverrides),
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
    $variant = ProductVariant::factory()->create();
    $lensCategory = LensCategory::factory()->create(['price' => 1000]);

    app(CreateQuotation::class)->handle(
        encounter: $encounter,
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
    );
})->throws(ValidationException::class);
