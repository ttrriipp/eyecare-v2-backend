<?php

use App\Enums\ReservationStatus;
use App\Filament\Resources\FrameReservations\Pages\EditFrameReservation;
use App\Filament\Resources\FrameReservations\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\FrameReservation;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can add another frame to a requested reservation from the relation manager', function () {
    $staff = User::factory()->staff()->create();
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);
    $variant = ProductVariant::factory()->create([
        'is_active' => true,
        'product_id' => Product::factory()->create(['product_type' => 'frame', 'is_active' => true])->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $reservation,
        'pageClass' => EditFrameReservation::class,
    ])
        ->assertActionVisible(TestAction::make('addFrame')->table())
        ->callAction(TestAction::make('addFrame')->table(), ['product_variant_id' => $variant->id])
        ->assertHasNoActionErrors();

    expect($reservation->items()->where('product_variant_id', $variant->id)->exists())->toBeTrue();
});

test('the add frame action is hidden once a reservation is tried on', function () {
    $staff = User::factory()->staff()->create();
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::TriedOn]);

    $this->actingAs($staff);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $reservation,
        'pageClass' => EditFrameReservation::class,
    ])
        ->assertActionHidden(TestAction::make('addFrame')->table());
});

test('staff can remove a frame from a requested reservation', function () {
    $staff = User::factory()->staff()->create();
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);
    $keptItem = $reservation->items()->create([
        'product_variant_id' => ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['product_type' => 'frame'])->id,
        ])->id,
    ]);
    $removedItem = $reservation->items()->create([
        'product_variant_id' => ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['product_type' => 'frame'])->id,
        ])->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $reservation,
        'pageClass' => EditFrameReservation::class,
    ])
        ->assertActionVisible(TestAction::make('removeFrame')->table($removedItem))
        ->callAction(TestAction::make('removeFrame')->table($removedItem))
        ->assertHasNoActionErrors();

    expect($reservation->items()->whereKey($removedItem->id)->exists())->toBeFalse()
        ->and($reservation->items()->whereKey($keptItem->id)->exists())->toBeTrue();
});

test('removing the last frame releases the reservation', function () {
    $staff = User::factory()->staff()->create();
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);
    $onlyItem = $reservation->items()->create([
        'product_variant_id' => ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['product_type' => 'frame'])->id,
        ])->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $reservation,
        'pageClass' => EditFrameReservation::class,
    ])
        ->callAction(TestAction::make('removeFrame')->table($onlyItem))
        ->assertHasNoActionErrors();

    expect($reservation->items()->whereKey($onlyItem->id)->exists())->toBeFalse()
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Released);
});

test('the remove frame action is hidden once a reservation is tried on', function () {
    $staff = User::factory()->staff()->create();
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::TriedOn]);
    $item = $reservation->items()->create([
        'product_variant_id' => ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['product_type' => 'frame'])->id,
        ])->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $reservation,
        'pageClass' => EditFrameReservation::class,
    ])
        ->assertActionHidden(TestAction::make('removeFrame')->table($item));
});

test('use in quotation opens a preselected patient reservation frame and current prescription', function () {
    $staff = User::factory()->staff()->create();
    $appointment = Appointment::factory()->create();
    $encounter = Encounter::factory()->completed()->create([
        'patient_id' => $appointment->patient_id,
        'appointment_id' => $appointment->id,
    ]);
    $prescription = Prescription::factory()->create([
        'patient_id' => $appointment->patient_id,
        'encounter_id' => $encounter->id,
    ]);
    $reservation = FrameReservation::factory()
        ->forAppointment($appointment)
        ->create(['status' => ReservationStatus::TriedOn]);
    $variant = ProductVariant::factory()->create([
        'product_id' => Product::factory()->create(['product_type' => 'frame'])->id,
    ]);
    $item = $reservation->items()->create(['product_variant_id' => $variant->id]);
    $url = QuotationResource::getUrl('create', [
        'patient' => $reservation->patient_id,
        'reservation' => $reservation->id,
        'frame_reservation_item' => $item->id,
        'encounter' => $encounter->id,
        'prescription' => $prescription->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $reservation,
        'pageClass' => EditFrameReservation::class,
    ])
        ->assertActionVisible(TestAction::make('useInQuotation')->table($item))
        ->assertActionHasUrl(TestAction::make('useInQuotation')->table($item), $url);
});

test('use in quotation is hidden for a converted reservation', function () {
    $staff = User::factory()->staff()->create();
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Converted]);
    $item = $reservation->items()->create([
        'product_variant_id' => ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['product_type' => 'frame'])->id,
        ])->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(ItemsRelationManager::class, [
        'ownerRecord' => $reservation,
        'pageClass' => EditFrameReservation::class,
    ])
        ->assertActionHidden(TestAction::make('useInQuotation')->table($item));
});
