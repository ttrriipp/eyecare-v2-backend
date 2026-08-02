<?php

use App\Actions\Quotations\UpdateQuotationDraft;
use App\Enums\QuotationStatus;
use App\Models\LensCategory;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('draft quotation items and totals can be updated', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'subtotal' => 5000,
        'discount_amount' => 0,
        'total' => 5000,
    ]);

    $quotation->items()->create([
        'description' => 'Old frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $variant = ProductVariant::factory()->create();
    $lensCategory = LensCategory::factory()->create(['price' => 2000]);

    $updated = app(UpdateQuotationDraft::class)->handle($quotation, [
        'discount_amount' => 500,
        'items' => [
            ['description' => 'New frame', 'quantity' => 1, 'unit_price' => 6000, 'product_variant_id' => $variant->id],
            ['description' => 'Lens', 'quantity' => 1, 'unit_price' => 2000, 'lens_category_id' => $lensCategory->id],
        ],
    ]);

    expect((float) $updated->subtotal)->toBe(8000.0)
        ->and((float) $updated->discount_amount)->toBe(500.0)
        ->and((float) $updated->total)->toBe(7500.0)
        ->and($updated->items)->toHaveCount(2)
        ->and($updated->items->first()->description)->toBe('New frame');
});

test('editing presented quotation returns it to draft and clears presentation metadata', function () {
    $staff = User::factory()->staff()->create();

    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Presented,
        'subtotal' => 5000,
        'discount_amount' => 0,
        'total' => 5000,
        'presented_by' => $staff->id,
        'presented_at' => now(),
    ]);

    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
    ]);

    $updated = app(UpdateQuotationDraft::class)->handle($quotation, [
        'items' => [
            ['description' => 'Updated frame', 'quantity' => 1, 'unit_price' => 5000],
        ],
    ]);

    expect($updated->status)->toBe(QuotationStatus::Draft)
        ->and($updated->presented_by)->toBeNull()
        ->and($updated->presented_at)->toBeNull();
});

test('accepted quotation cannot be edited', function () {
    $quotation = Quotation::factory()->accepted()->create();

    app(UpdateQuotationDraft::class)->handle($quotation, [
        'items' => [['description' => 'Frame', 'quantity' => 1, 'unit_price' => 1000]],
    ]);
})->throws(ValidationException::class, 'Only draft or presented quotations can be edited.');

test('declined quotation cannot be edited', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Declined]);

    app(UpdateQuotationDraft::class)->handle($quotation, [
        'items' => [['description' => 'Frame', 'quantity' => 1, 'unit_price' => 1000]],
    ]);
})->throws(ValidationException::class);

test('expired quotation cannot be edited', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Expired]);

    app(UpdateQuotationDraft::class)->handle($quotation, [
        'items' => [['description' => 'Frame', 'quantity' => 1, 'unit_price' => 1000]],
    ]);
})->throws(ValidationException::class);

test('discount cannot exceed subtotal on update', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);

    app(UpdateQuotationDraft::class)->handle($quotation, [
        'discount_amount' => 2000,
        'items' => [['description' => 'Frame', 'quantity' => 1, 'unit_price' => 1000]],
    ]);
})->throws(ValidationException::class);

test('items are replaced atomically on update', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);

    $quotation->items()->createMany([
        ['description' => 'Old item 1', 'quantity' => 1, 'unit_price' => 1000, 'amount' => 1000],
        ['description' => 'Old item 2', 'quantity' => 1, 'unit_price' => 2000, 'amount' => 2000],
    ]);

    app(UpdateQuotationDraft::class)->handle($quotation, [
        'items' => [
            ['description' => 'New item', 'quantity' => 2, 'unit_price' => 3000],
        ],
    ]);

    expect($quotation->fresh()->items)->toHaveCount(1)
        ->and($quotation->fresh()->items->first()->description)->toBe('New item');
});

test('invalid product variant is rejected on update', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft]);

    app(UpdateQuotationDraft::class)->handle($quotation, [
        'items' => [['description' => 'Frame', 'quantity' => 1, 'unit_price' => 1000, 'product_variant_id' => 999999]],
    ]);
})->throws(ValidationException::class);
