<?php

/**
 * Tests for eyewear specification shell creation at confirmation.
 *
 * @see tasks/todo.md Task 16
 */

use App\Actions\Quotations\ConfirmQuotationSale;
use App\Enums\CommercialItemKind;
use App\Enums\FrameSource;
use App\Enums\QuotationStatus;
use App\Enums\TransactionItemType;
use App\Models\Encounter;
use App\Models\JobOrderEyewearSpecification;
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

test('corrective confirmation creates a specification shell', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    $prescription = Prescription::factory()->linkedToEncounter($encounter)->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();

    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'prescription_id' => $prescription->id,
        'subtotal' => 8000,
        'total' => 8000,
    ]);

    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    $quotation->items()->create([
        'description' => 'Lens',
        'quantity' => 1,
        'unit_price' => 3000,
        'amount' => 3000,
        'lens_category_id' => $lensCategory->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::LensPackage,
    ]);

    $result = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );

    $spec = JobOrderEyewearSpecification::where('job_order_id', $result['optical_order']->id)->first();

    expect($spec)->not->toBeNull()
        ->and($spec->prescription_id)->toBe($prescription->id)
        ->and($spec->frame_source)->toBe(FrameSource::Catalog)
        ->and($spec->approved_by)->toBeNull()
        ->and($spec->approved_at)->toBeNull();
});

test('non-corrective order creates no specification', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'subtotal' => 5000,
        'total' => 5000,
    ]);

    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    $result = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );

    expect($result['optical_order']->eyewearSpecification)->toBeNull();
});

test('service-only order creates no specification', function () {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'subtotal' => 1500,
        'total' => 1500,
    ]);

    $quotation->items()->create([
        'description' => 'Eye Exam',
        'quantity' => 1,
        'unit_price' => 1500,
        'amount' => 1500,
        'item_type' => TransactionItemType::Service,
        'item_kind' => CommercialItemKind::Service,
    ]);

    $result = app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );

    expect($result['optical_order'])->toBeNull();
});

test('confirmation retry creates no second specification', function () {
    $encounter = Encounter::factory()->inProgress()->create();
    $prescription = Prescription::factory()->linkedToEncounter($encounter)->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 20]);
    $lensCategory = LensCategory::factory()->withPrice(3000)->create();

    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::Draft,
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->id,
        'prescription_id' => $prescription->id,
        'subtotal' => 8000,
        'total' => 8000,
    ]);

    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    $quotation->items()->create([
        'description' => 'Lens',
        'quantity' => 1,
        'unit_price' => 3000,
        'amount' => 3000,
        'lens_category_id' => $lensCategory->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::LensPackage,
    ]);

    app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation,
        confirmer: $this->staff,
    );

    app(ConfirmQuotationSale::class)->handle(
        quotation: $quotation->fresh(),
        confirmer: $this->staff,
    );

    expect(JobOrderEyewearSpecification::count())->toBe(1);
});
