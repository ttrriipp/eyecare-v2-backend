<?php

/**
 * Tests for admin-only discount authorization.
 *
 * @see tasks/todo.md Task 10
 */

use App\Actions\Quotations\CreateQuotation;
use App\Actions\Quotations\UpdateQuotationDraft;
use App\Enums\QuotationStatus;
use App\Models\Encounter;
use App\Models\Prescription;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->admin()->create();
    $this->staff = User::factory()->staff()->create();
    $this->optometrist = User::factory()->optometrist()->create();
});

test('admin can apply a nonzero discount', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();
    $variant = ProductVariant::factory()->create(['price' => 5000]);

    $quotation = app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $this->admin,
        data: [
            'discount_amount' => 500,
            'items' => [
                ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 5000, 'product_variant_id' => $variant->id],
            ],
        ],
        encounter: $encounter,
    );

    expect((float) $quotation->discount_amount)->toBe(500.0);
});

test('staff cannot apply a nonzero discount', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();
    $variant = ProductVariant::factory()->create(['price' => 5000]);

    app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $this->staff,
        data: [
            'discount_amount' => 500,
            'items' => [
                ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 5000, 'product_variant_id' => $variant->id],
            ],
        ],
        encounter: $encounter,
    );
})->throws(ValidationException::class, 'Only an admin can apply a discount');

test('optometrist cannot apply a nonzero discount', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();
    $variant = ProductVariant::factory()->create(['price' => 5000]);

    app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $this->optometrist,
        data: [
            'discount_amount' => 500,
            'items' => [
                ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 5000, 'product_variant_id' => $variant->id],
            ],
        ],
        encounter: $encounter,
    );
})->throws(ValidationException::class, 'Only an admin can apply a discount');

test('staff can create quotation with zero discount', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();
    $variant = ProductVariant::factory()->create(['price' => 5000]);

    $quotation = app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $this->staff,
        data: [
            'discount_amount' => 0,
            'items' => [
                ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 5000, 'product_variant_id' => $variant->id],
            ],
        ],
        encounter: $encounter,
    );

    expect((float) $quotation->discount_amount)->toBe(0.0);
});

test('staff cannot update discount to nonzero', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'subtotal' => 5000,
        'discount_amount' => 0,
        'total' => 5000,
    ]);

    $variant = ProductVariant::factory()->create(['price' => 5000]);

    app(UpdateQuotationDraft::class)->handle($quotation, [
        'discount_amount' => 500,
        'items' => [
            ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 5000, 'product_variant_id' => $variant->id],
        ],
    ], $this->staff);
})->throws(ValidationException::class, 'Only an admin can apply a discount');

test('admin can update discount to nonzero', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'subtotal' => 5000,
        'discount_amount' => 0,
        'total' => 5000,
    ]);

    $variant = ProductVariant::factory()->create(['price' => 5000]);

    $updated = app(UpdateQuotationDraft::class)->handle($quotation, [
        'discount_amount' => 1000,
        'items' => [
            ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 5000, 'product_variant_id' => $variant->id],
        ],
    ], $this->admin);

    expect((float) $updated->discount_amount)->toBe(1000.0);
});

test('discount cannot exceed subtotal', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();
    $variant = ProductVariant::factory()->create(['price' => 5000]);

    app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $this->admin,
        data: [
            'discount_amount' => 6000,
            'items' => [
                ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 5000, 'product_variant_id' => $variant->id],
            ],
        ],
        encounter: $encounter,
    );
})->throws(ValidationException::class, 'The discount cannot exceed the quotation subtotal');
