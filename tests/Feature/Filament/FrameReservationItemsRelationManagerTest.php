<?php

use App\Enums\ReservationStatus;
use App\Filament\Resources\FrameReservations\Pages\EditFrameReservation;
use App\Filament\Resources\FrameReservations\RelationManagers\ItemsRelationManager;
use App\Models\FrameReservation;
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
