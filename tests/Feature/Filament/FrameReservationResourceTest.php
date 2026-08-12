<?php

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

test('reservation table shows held state column', function () {
    $staff = User::factory()->staff()->create();
    $unaccepted = FrameReservation::factory()->create();

    $this->actingAs($staff);

    Livewire::test(ListFrameReservations::class)
        ->assertCanSeeTableRecords([$unaccepted])
        ->assertSee('Awaiting acceptance');
});

test('reservation resource is registered', function () {
    expect(FrameReservationResource::getModel())->toBe(FrameReservation::class);
});

test('accept action is available for an unaccepted reservation', function () {
    $staff = User::factory()->staff()->create();
    $reservation = FrameReservation::factory()->create();

    $this->actingAs($staff);

    Livewire::test(EditFrameReservation::class, ['record' => $reservation->getRouteKey()])
        ->assertSee('Accept & Set Aside');
});

test('the accept action is hidden for a held reservation', function () {
    $staff = User::factory()->staff()->create();
    $reservation = FrameReservation::factory()->accepted()->create();

    $this->actingAs($staff);

    Livewire::test(EditFrameReservation::class, ['record' => $reservation->getRouteKey()])
        ->assertDontSee('Accept & Set Aside');
});

test('release action is available for a reservation', function () {
    $staff = User::factory()->staff()->create();
    $reservation = FrameReservation::factory()->accepted()->create();

    $this->actingAs($staff);

    Livewire::test(EditFrameReservation::class, ['record' => $reservation->getRouteKey()])
        ->assertSee('Release Frames');
});
