<?php

use App\Enums\ReservationStatus;
use App\Filament\Resources\FrameReservations\FrameReservationResource;
use App\Filament\Resources\FrameReservations\Pages\EditFrameReservation;
use App\Filament\Resources\FrameReservations\Pages\ListFrameReservations;
use App\Models\FrameReservation;
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
