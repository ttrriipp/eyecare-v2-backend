<?php

use App\Actions\Quotations\ConfirmQuotationSale;
use App\Actions\Reservations\PrepareFrameReservation;
use App\Enums\CommercialItemKind;
use App\Enums\QuotationStatus;
use App\Enums\ReservationStatus;
use App\Enums\TransactionItemType;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use App\Models\FrameReservation;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
    $this->actingAs($this->staff);
});

test('prepared multi-frame reservation releases every candidate and commits only the quoted frame', function (): void {
    $patient = Patient::factory()->create();
    $selectedVariant = ProductVariant::factory()->create(['stock_quantity' => 10, 'price' => 4500]);
    $unselectedVariant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $accessoryVariant = ProductVariant::factory()->create([
        'product_id' => Product::factory()->accessory()->create()->id,
        'stock_quantity' => 10,
    ]);

    $reservation = FrameReservation::factory()->create(['patient_id' => $patient->id]);
    $reservation->items()->createMany([
        ['product_variant_id' => $selectedVariant->id],
        ['product_variant_id' => $unselectedVariant->id],
    ]);

    app(PrepareFrameReservation::class)->handle($reservation);

    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'frame_reservation_id' => $reservation->id,
        'status' => QuotationStatus::Draft,
    ]);
    $quotation->items()->createMany([
        [
            'description' => 'Selected frame',
            'quantity' => 1,
            'unit_price' => 4500,
            'amount' => 4500,
            'product_variant_id' => $selectedVariant->id,
            'item_type' => TransactionItemType::Product,
            'item_kind' => CommercialItemKind::Frame,
        ],
        [
            'description' => 'Accessory',
            'quantity' => 1,
            'unit_price' => 900,
            'amount' => 900,
            'product_variant_id' => $accessoryVariant->id,
            'item_type' => TransactionItemType::Product,
            'item_kind' => CommercialItemKind::Accessory,
        ],
    ]);

    $result = app(ConfirmQuotationSale::class)->handle($quotation, $this->staff);

    expect($selectedVariant->fresh()->stock_quantity)->toBe(9)
        ->and($unselectedVariant->fresh()->stock_quantity)->toBe(10)
        ->and($accessoryVariant->fresh()->stock_quantity)->toBe(9)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Converted)
        ->and($reservation->items()->count())->toBe(2)
        ->and($result['optical_order']->frame_reservation_id)->toBe($reservation->id)
        ->and(InventoryMovement::where('product_variant_id', $selectedVariant->id)->count())->toBe(3)
        ->and(InventoryMovement::where('product_variant_id', $unselectedVariant->id)->count())->toBe(2)
        ->and(InventoryMovement::where('product_variant_id', $accessoryVariant->id)->count())->toBe(1);
});

test('requested reservation commits the selected frame once and leaves unselected candidates unchanged', function (): void {
    $patient = Patient::factory()->create();
    $selectedVariant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $unselectedVariant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $reservation = FrameReservation::factory()->create(['patient_id' => $patient->id]);
    $reservation->items()->createMany([
        ['product_variant_id' => $selectedVariant->id],
        ['product_variant_id' => $unselectedVariant->id],
    ]);

    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'frame_reservation_id' => $reservation->id,
    ]);
    $quotation->items()->create([
        'description' => 'Selected frame',
        'quantity' => 1,
        'unit_price' => 4500,
        'amount' => 4500,
        'product_variant_id' => $selectedVariant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    app(ConfirmQuotationSale::class)->handle($quotation, $this->staff);

    expect($selectedVariant->fresh()->stock_quantity)->toBe(9)
        ->and($unselectedVariant->fresh()->stock_quantity)->toBe(10)
        ->and(InventoryMovement::where('product_variant_id', $selectedVariant->id)->count())->toBe(1)
        ->and(InventoryMovement::where('product_variant_id', $unselectedVariant->id)->count())->toBe(0)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Converted);
});

test('reservation validation rejects another patient, a missing quoted frame, and terminal statuses', function (): void {
    $patient = Patient::factory()->create();
    $otherPatient = Patient::factory()->create();
    $quotedVariant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $otherVariant = ProductVariant::factory()->create(['stock_quantity' => 10]);

    $cases = [
        'another patient' => [
            'reservation' => FrameReservation::factory()->create(['patient_id' => $otherPatient->id]),
            'variant' => $quotedVariant,
            'message' => 'belongs to another patient',
        ],
        'missing quoted frame' => [
            'reservation' => FrameReservation::factory()->create(['patient_id' => $patient->id]),
            'variant' => $otherVariant,
            'message' => 'does not contain the quoted frame variant',
        ],
    ];

    foreach ($cases as $case) {
        $case['reservation']->items()->create(['product_variant_id' => $quotedVariant->id]);
        $quotation = reservationQuotation(
            $patient,
            $case['reservation'],
            $case['variant'],
        );

        expect(fn () => app(ConfirmQuotationSale::class)->handle($quotation, $this->staff))
            ->toThrow(ValidationException::class, $case['message']);

        expect($quotation->fresh()->status)->toBe(QuotationStatus::Draft)
            ->and(JobOrder::where('quotation_id', $quotation->id)->exists())->toBeFalse()
            ->and(BillingRecord::where('quotation_id', $quotation->id)->exists())->toBeFalse();
    }

    foreach ([ReservationStatus::Released, ReservationStatus::Cancelled, ReservationStatus::Converted] as $status) {
        $reservation = FrameReservation::factory()->create([
            'patient_id' => $patient->id,
            'status' => $status,
        ]);
        $reservation->items()->create(['product_variant_id' => $quotedVariant->id]);
        $quotation = reservationQuotation($patient, $reservation, $quotedVariant);

        expect(fn () => app(ConfirmQuotationSale::class)->handle($quotation, $this->staff))
            ->toThrow(ValidationException::class, 'Only requested, prepared, or tried-on');
    }
});

test('failed conversion rolls back acceptance, order, billing, reservation, and inventory movements', function (): void {
    $patient = Patient::factory()->create();
    $selectedVariant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $unselectedVariant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $outOfStockAccessory = ProductVariant::factory()->create([
        'product_id' => Product::factory()->accessory()->create()->id,
        'stock_quantity' => 0,
    ]);
    $reservation = FrameReservation::factory()->create(['patient_id' => $patient->id]);
    $reservation->items()->createMany([
        ['product_variant_id' => $selectedVariant->id],
        ['product_variant_id' => $unselectedVariant->id],
    ]);
    app(PrepareFrameReservation::class)->handle($reservation);

    $quotation = reservationQuotation($patient, $reservation, $selectedVariant);
    $quotation->items()->create([
        'description' => 'Unavailable accessory',
        'quantity' => 1,
        'unit_price' => 500,
        'amount' => 500,
        'product_variant_id' => $outOfStockAccessory->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Accessory,
    ]);

    expect(fn () => app(ConfirmQuotationSale::class)->handle($quotation, $this->staff))
        ->toThrow(ValidationException::class, 'Insufficient stock');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Draft)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Prepared)
        ->and($selectedVariant->fresh()->stock_quantity)->toBe(9)
        ->and($unselectedVariant->fresh()->stock_quantity)->toBe(9)
        ->and(JobOrder::where('quotation_id', $quotation->id)->exists())->toBeFalse()
        ->and(BillingRecord::where('quotation_id', $quotation->id)->exists())->toBeFalse()
        ->and(InventoryMovement::where('reservation_id', $reservation->id)->count())->toBe(2);
});

test('repeating confirmation does not duplicate order billing payment or inventory records', function (): void {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $reservation = FrameReservation::factory()->create(['patient_id' => $patient->id]);
    $reservation->items()->create(['product_variant_id' => $variant->id]);
    $quotation = reservationQuotation($patient, $reservation, $variant);

    $first = app(ConfirmQuotationSale::class)->handle(
        $quotation,
        $this->staff,
        depositAmount: 1000,
    );
    $second = app(ConfirmQuotationSale::class)->handle(
        $quotation->fresh(),
        $this->staff,
        depositAmount: 1000,
    );

    expect($first['optical_order']->id)->toBe($second['optical_order']->id)
        ->and(JobOrder::where('quotation_id', $quotation->id)->count())->toBe(1)
        ->and(BillingRecord::where('quotation_id', $quotation->id)->count())->toBe(1)
        ->and(BillingRecordItem::where('billing_record_id', $first['billing_record']->id)->count())->toBe(1)
        ->and(BillingPayment::where('billing_record_id', $first['billing_record']->id)->count())->toBe(1)
        ->and(InventoryMovement::where('product_variant_id', $variant->id)->count())->toBe(1)
        ->and($variant->fresh()->stock_quantity)->toBe(9)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Converted);
});

test('a quotation without a reservation keeps the existing normal confirmation lifecycle', function (): void {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $quotation = Quotation::factory()->create(['patient_id' => $patient->id]);
    $quotation->items()->create([
        'description' => 'Catalog frame',
        'quantity' => 1,
        'unit_price' => 4500,
        'amount' => 4500,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    $result = app(ConfirmQuotationSale::class)->handle($quotation, $this->staff);

    expect($result['quotation']->status)->toBe(QuotationStatus::Accepted)
        ->and($result['optical_order']->frame_reservation_id)->toBeNull()
        ->and($variant->fresh()->stock_quantity)->toBe(9)
        ->and(InventoryMovement::where('product_variant_id', $variant->id)->count())->toBe(1);
});

function reservationQuotation(Patient $patient, FrameReservation $reservation, ProductVariant $variant): Quotation
{
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'frame_reservation_id' => $reservation->id,
        'status' => QuotationStatus::Draft,
    ]);
    $quotation->items()->create([
        'description' => 'Selected frame',
        'quantity' => 1,
        'unit_price' => 4500,
        'amount' => 4500,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    return $quotation;
}
