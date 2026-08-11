<?php

use App\Actions\Quotations\CreateQuotation;
use App\Actions\Quotations\UpdateQuotationDraft;
use App\Enums\CommercialItemKind;
use App\Enums\QuotationStatus;
use App\Enums\ReservationStatus;
use App\Enums\TransactionItemType;
use App\Filament\Resources\Quotations\Pages\CreateQuotation as CreateQuotationPage;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->staff()->create();
});

test('quotation reservation options only include eligible items for the current patient', function () {
    $patient = Patient::factory()->create();
    $otherPatient = Patient::factory()->create();
    $eligibleItems = collect([
        ReservationStatus::Requested,
        ReservationStatus::Prepared,
        ReservationStatus::TriedOn,
    ])->map(function (ReservationStatus $status) use ($patient): FrameReservationItem {
        $reservation = FrameReservation::factory()->create([
            'patient_id' => $patient->id,
            'status' => $status,
        ]);

        return $reservation->items()->create([
            'product_variant_id' => ProductVariant::factory()->create()->id,
        ]);
    });

    $otherPatientItem = FrameReservation::factory()
        ->create(['patient_id' => $otherPatient->id])
        ->items()
        ->create(['product_variant_id' => ProductVariant::factory()->create()->id]);

    foreach ([ReservationStatus::Released, ReservationStatus::Cancelled, ReservationStatus::Converted] as $status) {
        $reservation = FrameReservation::factory()->create([
            'patient_id' => $patient->id,
            'status' => $status,
        ]);

        $reservation->items()->create([
            'product_variant_id' => ProductVariant::factory()->create()->id,
        ]);
    }

    $inactiveVariant = ProductVariant::factory()->create(['is_active' => false]);
    $inactiveReservation = FrameReservation::factory()->create(['patient_id' => $patient->id]);
    $inactiveReservation->items()->create(['product_variant_id' => $inactiveVariant->id]);

    $inactiveProduct = Product::factory()->inactive()->create();
    $inactiveProductReservation = FrameReservation::factory()->create(['patient_id' => $patient->id]);
    $inactiveProductReservation->items()->create([
        'product_variant_id' => ProductVariant::factory()->create(['product_id' => $inactiveProduct->id])->id,
    ]);

    $convertedByOrder = FrameReservation::factory()->create(['patient_id' => $patient->id]);
    $convertedByOrderItem = $convertedByOrder->items()->create([
        'product_variant_id' => ProductVariant::factory()->create()->id,
    ]);
    JobOrder::factory()->create([
        'patient_id' => $patient->id,
        'frame_reservation_id' => $convertedByOrder->id,
    ]);

    expect(FrameReservationItem::query()
        ->eligibleForQuotation($patient->id)
        ->pluck('id')
        ->all())->toEqualCanonicalizing($eligibleItems->pluck('id')->all())
        ->and($otherPatientItem->id)->not->toBeIn($eligibleItems->pluck('id')->all())
        ->and($convertedByOrderItem->id)->not->toBeIn($eligibleItems->pluck('id')->all());
});

test('selecting a reserved frame creates exactly one matching quotation frame line', function () {
    $patient = Patient::factory()->create();
    $selectedVariant = ProductVariant::factory()->create([
        'name' => 'Black',
        'price' => 4500,
    ]);
    $otherVariant = ProductVariant::factory()->create([
        'name' => 'Tortoise',
        'price' => 4700,
    ]);
    $reservation = FrameReservation::factory()->create(['patient_id' => $patient->id]);
    $selectedItem = $reservation->items()->create(['product_variant_id' => $selectedVariant->id]);

    $quotation = app(CreateQuotation::class)->handle(
        patient: $patient,
        creator: $this->staff,
        data: [
            'frame_reservation_item_id' => $selectedItem->id,
            'items' => [
                [
                    'item_type' => 'catalog',
                    'description' => 'An unselected frame',
                    'quantity' => 1,
                    'unit_price' => 9999,
                    'product_variant_id' => $otherVariant->id,
                ],
            ],
        ],
    );

    $frameItems = $quotation->fresh()->items
        ->where('item_kind', CommercialItemKind::Frame)
        ->whereNotNull('product_variant_id');

    expect($quotation->frame_reservation_id)->toBe($reservation->id)
        ->and($frameItems)->toHaveCount(1)
        ->and($frameItems->first()->product_variant_id)->toBe($selectedVariant->id)
        ->and($frameItems->first()->description)->toBe($selectedVariant->product->name.' — '.$selectedVariant->name)
        ->and((float) $frameItems->first()->unit_price)->toBe((float) $selectedVariant->price);
});

test('changing a quotation frame away from its selected reservation clears the source', function () {
    $patient = Patient::factory()->create();
    $reservedVariant = ProductVariant::factory()->create();
    $otherVariant = ProductVariant::factory()->create();
    $reservation = FrameReservation::factory()->create(['patient_id' => $patient->id]);
    $selectedItem = $reservation->items()->create(['product_variant_id' => $reservedVariant->id]);
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'frame_reservation_id' => $reservation->id,
        'status' => QuotationStatus::Draft,
    ]);
    $quotation->items()->create([
        'description' => 'Reserved frame',
        'quantity' => 1,
        'unit_price' => 4500,
        'amount' => 4500,
        'product_variant_id' => $reservedVariant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    app(UpdateQuotationDraft::class)->handle($quotation, [
        'frame_reservation_item_id' => null,
        'items' => [[
            'item_type' => 'catalog',
            'description' => 'Another frame',
            'quantity' => 1,
            'unit_price' => 5000,
            'product_variant_id' => $otherVariant->id,
        ]],
    ]);
    expect($quotation->fresh()->frame_reservation_id)->toBeNull();
});

test('saving a quotation draft preserves the selected reservation source', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();
    $reservation = FrameReservation::factory()->create(['patient_id' => $patient->id]);
    $reservationItem = $reservation->items()->create(['product_variant_id' => $variant->id]);

    $this->actingAs($this->staff);

    Livewire::test(CreateQuotationPage::class, ['patient' => (string) $patient->id])
        ->fillForm([
            'frame_reservation_item_id' => $reservationItem->id,
            'items' => [[
                'item_type' => 'custom_service',
                'description' => 'Frame fitting',
                'quantity' => 1,
                'unit_price' => 500,
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $quotation = Quotation::query()->where('patient_id', $patient->id)->firstOrFail();

    expect($quotation->frame_reservation_id)->toBe($reservation->id)
        ->and($quotation->items()->where('product_variant_id', $variant->id)->count())->toBe(1);
});

test('presenting a quotation preserves the selected reservation source', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();
    $reservation = FrameReservation::factory()->create(['patient_id' => $patient->id]);
    $reservationItem = $reservation->items()->create(['product_variant_id' => $variant->id]);

    $this->actingAs($this->staff);

    Livewire::test(CreateQuotationPage::class, ['patient' => (string) $patient->id])
        ->fillForm([
            'frame_reservation_item_id' => $reservationItem->id,
            'items' => [[
                'item_type' => 'custom_service',
                'description' => 'Frame fitting',
                'quantity' => 1,
                'unit_price' => 500,
            ]],
        ])
        ->set('creationMode', 'presented')
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $quotation = Quotation::query()->where('patient_id', $patient->id)->firstOrFail();

    expect($quotation->status)->toBe(QuotationStatus::Presented)
        ->and($quotation->frame_reservation_id)->toBe($reservation->id);
});

test('accept and continue converts the selected reservation from quotation creation', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $reservation = FrameReservation::factory()->create(['patient_id' => $patient->id]);
    $reservationItem = $reservation->items()->create(['product_variant_id' => $variant->id]);

    $this->actingAs($this->staff);

    Livewire::test(CreateQuotationPage::class, [
        'patient' => (string) $patient->id,
        'reservation' => (string) $reservation->id,
        'frameReservationItem' => (string) $reservationItem->id,
    ])
        ->assertFormSet(['frame_reservation_item_id' => $reservationItem->id])
        ->fillForm([
            'items' => [[
                'item_type' => 'custom_service',
                'description' => 'Frame fitting',
                'quantity' => 1,
                'unit_price' => 500,
            ]],
        ])
        ->set('creationMode', 'accepted')
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $quotation = Quotation::query()->where('patient_id', $patient->id)->firstOrFail();
    $jobOrder = JobOrder::query()->where('quotation_id', $quotation->id)->firstOrFail();

    expect($quotation->status)->toBe(QuotationStatus::Accepted)
        ->and($quotation->frame_reservation_id)->toBe($reservation->id)
        ->and($jobOrder->frame_reservation_id)->toBe($reservation->id)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Converted)
        ->and($variant->fresh()->stock_quantity)->toBe(9)
        ->and(InventoryMovement::where('product_variant_id', $variant->id)->count())->toBe(1);
});

test('normal confirm sale converts the persisted selected reservation', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $reservation = FrameReservation::factory()->create(['patient_id' => $patient->id]);
    $reservationItem = $reservation->items()->create(['product_variant_id' => $variant->id]);
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'frame_reservation_id' => $reservation->id,
        'status' => QuotationStatus::Presented,
    ]);
    $quotation->items()->create([
        'description' => 'Reserved frame',
        'quantity' => 1,
        'unit_price' => 4500,
        'amount' => 4500,
        'product_variant_id' => $variant->id,
        'item_type' => TransactionItemType::Product,
        'item_kind' => CommercialItemKind::Frame,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
        ->callAction('confirmSale')
        ->assertHasNoActionErrors();

    expect($reservationItem->exists)->toBeTrue()
        ->and($quotation->fresh()->status)->toBe(QuotationStatus::Accepted)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Converted)
        ->and(JobOrder::where('quotation_id', $quotation->id)->where('frame_reservation_id', $reservation->id)->exists())->toBeTrue()
        ->and($variant->fresh()->stock_quantity)->toBe(9);
});

test('revising a quotation can select a specific reserved frame', function () {
    $patient = Patient::factory()->create();
    $variant = ProductVariant::factory()->create();
    $reservation = FrameReservation::factory()->create(['patient_id' => $patient->id]);
    $reservationItem = $reservation->items()->create(['product_variant_id' => $variant->id]);
    $quotation = Quotation::factory()->create([
        'patient_id' => $patient->id,
        'status' => QuotationStatus::Draft,
    ]);
    $quotation->items()->create([
        'description' => 'Fitting service',
        'quantity' => 1,
        'unit_price' => 500,
        'amount' => 500,
        'item_type' => TransactionItemType::Service,
        'item_kind' => CommercialItemKind::Service,
    ]);

    $this->actingAs($this->staff);

    Livewire::test(EditQuotation::class, ['record' => $quotation->getRouteKey()])
        ->callAction('reviseItems', [
            'frame_reservation_item_id' => $reservationItem->id,
            'items' => [[
                'item_type' => 'custom_service',
                'description' => 'Updated fitting service',
                'quantity' => 1,
                'unit_price' => 600,
            ]],
        ])
        ->assertHasNoActionErrors();

    $quotation->refresh();

    expect($quotation->frame_reservation_id)->toBe($reservation->id)
        ->and($quotation->items()->where('product_variant_id', $variant->id)->count())->toBe(1)
        ->and($quotation->items()->where('item_kind', CommercialItemKind::Frame)->count())->toBe(1);
});
