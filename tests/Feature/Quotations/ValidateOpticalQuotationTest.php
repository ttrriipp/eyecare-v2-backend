<?php

/**
 * Tests for ValidateOpticalQuotation action.
 *
 * @see tasks/todo.md Task 11
 */

use App\Actions\Quotations\ValidateOpticalQuotation;
use App\Enums\CommercialItemKind;
use App\Models\Patient;
use App\Models\Prescription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->patient = Patient::factory()->create();
    $this->prescription = Prescription::factory()->create(['patient_id' => $this->patient->id]);
});

test('corrective eyewear requires exactly one lens package', function () {
    $items = collect([
        ['item_kind' => CommercialItemKind::LensPackage, 'product_variant_id' => null],
        ['item_kind' => CommercialItemKind::LensPackage, 'product_variant_id' => null],
    ]);

    app(ValidateOpticalQuotation::class)->handle($items, patient: $this->patient, prescription: $this->prescription);
})->throws(ValidationException::class, 'exactly one lens package');

test('corrective eyewear with one lens package is valid', function () {
    $items = collect([
        ['item_kind' => CommercialItemKind::Frame, 'product_variant_id' => 1],
        ['item_kind' => CommercialItemKind::LensPackage, 'product_variant_id' => null],
    ]);

    $result = app(ValidateOpticalQuotation::class)->handle(
        $items,
        patient: $this->patient,
        prescription: $this->prescription,
    );

    expect($result['is_corrective'])->toBeTrue()
        ->and($result['has_frame'])->toBeTrue()
        ->and($result['has_lens_package'])->toBeTrue();
});

test('at most one frame is allowed', function () {
    $items = collect([
        ['item_kind' => CommercialItemKind::Frame, 'product_variant_id' => 1],
        ['item_kind' => CommercialItemKind::Frame, 'product_variant_id' => 2],
        ['item_kind' => CommercialItemKind::LensPackage, 'product_variant_id' => null],
    ]);

    app(ValidateOpticalQuotation::class)->handle($items, patient: $this->patient, prescription: $this->prescription);
})->throws(ValidationException::class, 'at most one frame');

test('patient-supplied frame is accepted without a fake product', function () {
    $items = collect([
        ['item_kind' => CommercialItemKind::LensPackage, 'product_variant_id' => null],
    ]);

    $result = app(ValidateOpticalQuotation::class)->handle(
        $items,
        patient: $this->patient,
        prescription: $this->prescription,
    );

    expect($result['is_corrective'])->toBeTrue()
        ->and($result['has_frame'])->toBeFalse();
});

test('lens options require a lens package', function () {
    $items = collect([
        ['item_kind' => CommercialItemKind::Frame, 'product_variant_id' => 1],
        ['item_kind' => CommercialItemKind::LensOption, 'product_variant_id' => null],
    ]);

    app(ValidateOpticalQuotation::class)->handle($items);
})->throws(ValidationException::class, 'Lens options require a lens package');

test('lens options with lens package are valid', function () {
    $items = collect([
        ['item_kind' => CommercialItemKind::Frame, 'product_variant_id' => 1],
        ['item_kind' => CommercialItemKind::LensPackage, 'product_variant_id' => null],
        ['item_kind' => CommercialItemKind::LensOption, 'product_variant_id' => null],
    ]);

    $result = app(ValidateOpticalQuotation::class)->handle(
        $items,
        patient: $this->patient,
        prescription: $this->prescription,
    );

    expect($result['is_corrective'])->toBeTrue();
});

test('service-only quotation is valid and not corrective', function () {
    $items = collect([
        ['item_kind' => CommercialItemKind::Service, 'product_variant_id' => null],
    ]);

    $result = app(ValidateOpticalQuotation::class)->handle($items);

    expect($result['is_corrective'])->toBeFalse()
        ->and($result['has_frame'])->toBeFalse()
        ->and($result['has_lens_package'])->toBeFalse();
});

test('non-corrective product-only quotation is valid', function () {
    $items = collect([
        ['item_kind' => CommercialItemKind::Frame, 'product_variant_id' => 1],
    ]);

    $result = app(ValidateOpticalQuotation::class)->handle($items);

    expect($result['is_corrective'])->toBeFalse();
});

test('mixed quotation with products and services is valid', function () {
    $items = collect([
        ['item_kind' => CommercialItemKind::Frame, 'product_variant_id' => 1],
        ['item_kind' => CommercialItemKind::LensPackage, 'product_variant_id' => null],
        ['item_kind' => CommercialItemKind::Service, 'product_variant_id' => null],
    ]);

    $result = app(ValidateOpticalQuotation::class)->handle(
        $items,
        patient: $this->patient,
        prescription: $this->prescription,
    );

    expect($result['is_corrective'])->toBeTrue();
});

test('contact lenses and accessories alongside corrective build are valid', function () {
    $items = collect([
        ['item_kind' => CommercialItemKind::Frame, 'product_variant_id' => 1],
        ['item_kind' => CommercialItemKind::LensPackage, 'product_variant_id' => null],
        ['item_kind' => CommercialItemKind::ContactLens, 'product_variant_id' => 2],
        ['item_kind' => CommercialItemKind::Accessory, 'product_variant_id' => 3],
    ]);

    $result = app(ValidateOpticalQuotation::class)->handle(
        $items,
        patient: $this->patient,
        prescription: $this->prescription,
    );

    expect($result['is_corrective'])->toBeTrue();
});
