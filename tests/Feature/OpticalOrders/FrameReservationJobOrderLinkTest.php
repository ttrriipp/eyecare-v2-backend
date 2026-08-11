<?php

use App\Actions\OpticalOrders\AcceptAndStartOpticalOrder;
use App\Enums\CommercialItemKind;
use App\Enums\QuotationStatus;
use App\Enums\ReservationStatus;
use App\Enums\TransactionItemType;
use App\Models\BillingRecord;
use App\Models\FrameReservation;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->actingAs($this->staff);
});

test('confirmation commits inventory for catalog-backed items', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $quotation = Quotation::factory()->presented()->create(['total' => 5000]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 2,
        'unit_price' => 2500,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
    ]);

    app(AcceptAndStartOpticalOrder::class)->handle($quotation);

    expect($variant->fresh()->stock_quantity)->toBe(8);

    $movements = InventoryMovement::where('product_variant_id', $variant->id)->get();
    expect($movements)->toHaveCount(1)
        ->and($movements->first()->quantity_change)->toBe(-2);
});

test('confirmation does not affect inventory for service items', function () {
    $initialMovementCount = InventoryMovement::count();
    $quotation = Quotation::factory()->presented()->create(['total' => 750]);
    $quotation->items()->create([
        'description' => 'Fitting service',
        'quantity' => 1,
        'unit_price' => 750,
        'amount' => 750,
    ]);

    app(AcceptAndStartOpticalOrder::class)->handle($quotation);

    expect(InventoryMovement::count())->toBe($initialMovementCount);
});

test('insufficient stock rolls back entire confirmation', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 1]);

    $quotation = Quotation::factory()->presented()->create(['total' => 5000]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 5,
        'unit_price' => 1000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
    ]);

    try {
        app(AcceptAndStartOpticalOrder::class)->handle($quotation);
    } catch (ValidationException) {
        expect($quotation->fresh()->status)->toBe(QuotationStatus::Presented)
            ->and(JobOrder::where('quotation_id', $quotation->id)->exists())->toBeFalse()
            ->and(BillingRecord::count())->toBe(0)
            ->and($variant->fresh()->stock_quantity)->toBe(1);

        return;
    }

    $this->fail('Expected validation exception for insufficient stock.');
});

test('confirmation without reservation commits inventory normally', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $quotation = Quotation::factory()->presented()->create(['total' => 5000]);
    $quotation->items()->create([
        'description' => 'Frame',
        'quantity' => 3,
        'unit_price' => 1666.67,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
    ]);

    $result = app(AcceptAndStartOpticalOrder::class)->handle($quotation);

    expect($variant->fresh()->stock_quantity)->toBe(7)
        ->and($result['job_order']->frame_reservation_id)->toBeNull();
});

test('multiple variants each have inventory committed', function () {
    $variant1 = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $variant2 = ProductVariant::factory()->create(['stock_quantity' => 20]);

    $quotation = Quotation::factory()->presented()->create(['total' => 9000]);
    $quotation->items()->createMany([
        ['description' => 'Frame A', 'quantity' => 2, 'unit_price' => 2500, 'amount' => 5000, 'product_variant_id' => $variant1->id],
        ['description' => 'Frame B', 'quantity' => 4, 'unit_price' => 1000, 'amount' => 4000, 'product_variant_id' => $variant2->id],
    ]);

    app(AcceptAndStartOpticalOrder::class)->handle($quotation);

    expect($variant1->fresh()->stock_quantity)->toBe(8)
        ->and($variant2->fresh()->stock_quantity)->toBe(16);
});

test('legacy acceptance validates and converts the selected reservation once', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);
    $reservation->items()->create(['product_variant_id' => $variant->id]);

    $quotation = Quotation::factory()->presented()->create([
        'patient_id' => $reservation->patient_id,
        'total' => 5000,
    ]);
    $quotation->items()->create([
        'description' => 'Reserved frame',
        'quantity' => 1,
        'unit_price' => 5000,
        'amount' => 5000,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    $result = app(AcceptAndStartOpticalOrder::class)->handle(
        $quotation,
        frameReservationId: $reservation->id,
    );

    expect($result['quotation']->frame_reservation_id)->toBe($reservation->id)
        ->and($result['job_order']->frame_reservation_id)->toBe($reservation->id)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Converted)
        ->and($variant->fresh()->stock_quantity)->toBe(9)
        ->and(InventoryMovement::where('product_variant_id', $variant->id)->count())->toBe(1);
});
