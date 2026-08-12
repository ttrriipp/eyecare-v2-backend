<?php

use App\Actions\Quotations\CreateQuotation;
use App\Actions\Quotations\UpdateQuotationDraft;
use App\Enums\CommercialItemKind;
use App\Enums\QuotationStatus;
use App\Enums\TransactionItemType;
use App\Models\Encounter;
use App\Models\LensCategory;
use App\Models\Prescription;
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

test('product line gets product item type', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();
    $variant = ProductVariant::factory()->create();

    $quotation = app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $this->staff,
        data: [
            'items' => [
                ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 5000, 'product_variant_id' => $variant->id],
            ],
        ],
        encounter: $encounter,
    );

    expect($quotation->items->first()->item_type)->toBe(TransactionItemType::Product);
});

test('lens line gets product item type', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();
    $lens = LensCategory::factory()->create(['price' => 2000]);

    $quotation = app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $this->staff,
        data: [
            'items' => [
                ['description' => 'Lens', 'quantity' => 1, 'unit_price' => 2000, 'lens_category_id' => $lens->id],
            ],
        ],
        encounter: $encounter,
    );

    expect($quotation->items->first()->item_type)->toBe(TransactionItemType::Product);
});

test('service line gets service item type', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $quotation = app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $this->staff,
        data: [
            'items' => [
                ['description' => 'Eye Exam', 'quantity' => 1, 'unit_price' => 1500],
            ],
        ],
        encounter: $encounter,
    );

    expect($quotation->items->first()->item_type)->toBe(TransactionItemType::Service);
});

test('mixed order has both product and service types', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();
    $variant = ProductVariant::factory()->create();

    $quotation = app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $this->staff,
        data: [
            'items' => [
                ['description' => 'Frame', 'quantity' => 1, 'unit_price' => 5000, 'product_variant_id' => $variant->id],
                ['description' => 'Fitting', 'quantity' => 1, 'unit_price' => 500],
            ],
        ],
        encounter: $encounter,
    );

    expect($quotation->items->first()->item_type)->toBe(TransactionItemType::Product)
        ->and($quotation->items->last()->item_type)->toBe(TransactionItemType::Service);
});

test('update preserves item types', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'subtotal' => 5000,
        'total' => 5000,
    ]);

    $variant = ProductVariant::factory()->create();

    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::CustomProduct,
    ]);

    $updated = app(UpdateQuotationDraft::class)->handle($quotation, [
        'items' => [
            ['description' => 'Frame', 'quantity' => 2, 'unit_price' => 3000, 'product_variant_id' => $variant->id],
            ['description' => 'Service', 'quantity' => 1, 'unit_price' => 1000],
        ],
    ]);

    expect($updated->items->first()->item_type)->toBe(TransactionItemType::Product)
        ->and($updated->items->last()->item_type)->toBe(TransactionItemType::Service);
});

test('a custom item explicitly typed as a product is not misreported as a service', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $quotation = app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $this->staff,
        data: [
            'items' => [
                ['item_kind' => 'custom_product', 'description' => 'Off-catalog designer frame', 'quantity' => 1, 'unit_price' => 5000],
            ],
        ],
        encounter: $encounter,
    );

    expect($quotation->items->first()->item_type)->toBe(TransactionItemType::Product);
});

test('a custom item explicitly typed as a service is reported as a service', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    Prescription::factory()->linkedToEncounter($encounter)->create();

    $quotation = app(CreateQuotation::class)->handle(
        patient: $encounter->patient,
        creator: $this->staff,
        data: [
            'items' => [
                ['item_kind' => 'custom_service', 'description' => 'One-off repair fee', 'quantity' => 1, 'unit_price' => 300],
            ],
        ],
        encounter: $encounter,
    );

    expect($quotation->items->first()->item_type)->toBe(TransactionItemType::Service);
});
