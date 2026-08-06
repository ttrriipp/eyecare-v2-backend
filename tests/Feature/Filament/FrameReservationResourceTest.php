<?php

use App\Enums\ReservationStatus;
use App\Filament\Resources\FrameReservations\FrameReservationResource;
use App\Filament\Resources\FrameReservations\Pages\EditFrameReservation;
use App\Filament\Resources\FrameReservations\Pages\ListFrameReservations;
use App\Models\FrameReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('staff can list frame reservations', function () {
    $staff = User::factory()->staff()->create();
    $reservations = FrameReservation::factory()->count(3)->create();

    $this->actingAs($staff);

    Livewire::test(ListFrameReservations::class)
        ->assertCanSeeTableRecords($reservations);
});

test('staff can view a reservation', function () {
    $staff = User::factory()->staff()->create();
    $reservation = FrameReservation::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditFrameReservation::class, ['record' => $reservation->getRouteKey()])
        ->assertSuccessful();
});

test('reservation table shows status badges', function () {
    $staff = User::factory()->staff()->create();
    $requested = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);
    $prepared = FrameReservation::factory()->prepared()->create();

    $this->actingAs($staff);

    Livewire::test(ListFrameReservations::class)
        ->assertTableColumnFormattedStateSet('status', 'Requested', record: $requested)
        ->assertTableColumnFormattedStateSet('status', 'Prepared', record: $prepared);
});

test('reservation resource is registered', function () {
    expect(FrameReservationResource::getModel())->toBe(FrameReservation::class);
});

test('staff marks a prepared reservation as tried on', function () {
    $staff = User::factory()->staff()->create();
    $reservation = FrameReservation::factory()->prepared()->create();

    $this->actingAs($staff);

    Livewire::test(EditFrameReservation::class, ['record' => $reservation->getRouteKey()])
        ->assertActionVisible('triedOn')
        ->callAction('triedOn')
        ->assertHasNoActionErrors();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::TriedOn);
});

test('the tried on action is hidden for a requested reservation', function () {
    $staff = User::factory()->staff()->create();
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);

    $this->actingAs($staff);

    Livewire::test(EditFrameReservation::class, ['record' => $reservation->getRouteKey()])
        ->assertActionHidden('triedOn');
});

test('staff can release a tried-on reservation', function () {
    $staff = User::factory()->staff()->create();
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::TriedOn]);

    $this->actingAs($staff);

    Livewire::test(EditFrameReservation::class, ['record' => $reservation->getRouteKey()])
        ->assertActionVisible('release')
        ->callAction('release')
        ->assertHasNoActionErrors();

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Released);
});

test('staff can add another frame to a requested reservation', function () {
    $staff = User::factory()->staff()->create();
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);
    $variant = ProductVariant::factory()->create([
        'is_active' => true,
        'product_id' => Product::factory()->create(['product_type' => 'frame', 'is_active' => true])->id,
    ]);

    $this->actingAs($staff);

    Livewire::test(EditFrameReservation::class, ['record' => $reservation->getRouteKey()])
        ->assertActionVisible('addFrame')
        ->callAction('addFrame', ['product_variant_id' => $variant->id])
        ->assertHasNoActionErrors();

    expect($reservation->items()->where('product_variant_id', $variant->id)->exists())->toBeTrue();
});

test('the add frame action is hidden once a reservation is tried on', function () {
    $staff = User::factory()->staff()->create();
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::TriedOn]);

    $this->actingAs($staff);

    Livewire::test(EditFrameReservation::class, ['record' => $reservation->getRouteKey()])
        ->assertActionHidden('addFrame');
});
