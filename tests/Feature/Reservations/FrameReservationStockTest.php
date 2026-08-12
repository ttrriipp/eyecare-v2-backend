<?php

use App\Actions\Reservations\FrameReservationStock;
use App\Models\FrameReservation;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->stock = app(FrameReservationStock::class);
});

test('allocate decrements stock and writes one movement', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $reservation = FrameReservation::factory()->create();

    $this->stock->allocate($reservation, $variant->id);

    expect($variant->fresh()->stock_quantity)->toBe(9);
    expect(InventoryMovement::where('product_variant_id', $variant->id)
        ->where('reservation_id', $reservation->id)
        ->where('quantity_change', -1)
        ->count())->toBe(1);
});

test('allocate fails and decrements nothing when out of stock', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 0]);
    $reservation = FrameReservation::factory()->create();

    $this->expectException(ValidationException::class);
    $this->stock->allocate($reservation, $variant->id);

    expect($variant->fresh()->stock_quantity)->toBe(0);
    expect(InventoryMovement::where('product_variant_id', $variant->id)->count())->toBe(0);
});

test('release increments stock and writes one movement', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 5]);
    $reservation = FrameReservation::factory()->create();

    $this->stock->release($reservation, $variant->id);

    expect($variant->fresh()->stock_quantity)->toBe(6);
    expect(InventoryMovement::where('product_variant_id', $variant->id)
        ->where('reservation_id', $reservation->id)
        ->where('quantity_change', 1)
        ->count())->toBe(1);
});

test('allocate then release returns stock to baseline with two movements', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $reservation = FrameReservation::factory()->create();

    $this->stock->allocate($reservation, $variant->id);
    expect($variant->fresh()->stock_quantity)->toBe(9);

    $this->stock->release($reservation, $variant->id);
    expect($variant->fresh()->stock_quantity)->toBe(10);

    expect(InventoryMovement::where('product_variant_id', $variant->id)
        ->where('reservation_id', $reservation->id)
        ->count())->toBe(2);
});
