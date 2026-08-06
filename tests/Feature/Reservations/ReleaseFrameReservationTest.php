<?php

use App\Actions\Reservations\ReleaseFrameReservation;
use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use App\Models\ProductVariant;

test('releasing a requested reservation does not touch stock', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);
    $reservation->items()->create(['product_variant_id' => $variant->id]);

    app(ReleaseFrameReservation::class)->handle($reservation);

    expect($variant->fresh()->stock_quantity)->toBe(10)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Released);
});

test('releasing a prepared reservation restores its allocated stock', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 9]);
    $reservation = FrameReservation::factory()->prepared()->create();
    $reservation->items()->create(['product_variant_id' => $variant->id]);

    app(ReleaseFrameReservation::class)->handle($reservation);

    expect($variant->fresh()->stock_quantity)->toBe(10)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Released);
});

test('releasing a tried-on reservation also restores its allocated stock', function () {
    $variant = ProductVariant::factory()->create(['stock_quantity' => 9]);
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::TriedOn]);
    $reservation->items()->create(['product_variant_id' => $variant->id]);

    app(ReleaseFrameReservation::class)->handle($reservation);

    expect($variant->fresh()->stock_quantity)->toBe(10)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Released);
});
