<?php

use App\Actions\Reservations\PrepareFrameReservation;
use App\Actions\Reservations\ReleaseFrameReservation;
use App\Enums\ReservationStatus;
use App\Models\Appointment;
use App\Models\Brand;
use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('prepare reservation decrements stock for each item', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant1 = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 5]);
    $variant2 = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 3]);

    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);
    FrameReservationItem::factory()->create(['frame_reservation_id' => $reservation->id, 'product_variant_id' => $variant1->id]);
    FrameReservationItem::factory()->create(['frame_reservation_id' => $reservation->id, 'product_variant_id' => $variant2->id]);

    app(PrepareFrameReservation::class)->handle($reservation);

    expect($variant1->fresh()->stock_quantity)->toBe(4)
        ->and($variant2->fresh()->stock_quantity)->toBe(2)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Prepared);
});

test('prepare sets an expiry at the appointment day close time', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 5]);
    $appointment = Appointment::factory()->create(['scheduled_at' => now()->addDay()->setTime(10, 0)]);

    $reservation = FrameReservation::factory()->create([
        'status' => ReservationStatus::Requested,
        'appointment_id' => $appointment->id,
    ]);
    FrameReservationItem::factory()->create(['frame_reservation_id' => $reservation->id, 'product_variant_id' => $variant->id]);

    app(PrepareFrameReservation::class)->handle($reservation);

    $expiresAt = $reservation->fresh()->expires_at;

    expect($expiresAt)->not->toBeNull()
        ->and($expiresAt->toDateString())->toBe($appointment->scheduled_at->toDateString());
});

test('prepare rejects when variant is out of stock', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 0]);

    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);
    FrameReservationItem::factory()->create(['frame_reservation_id' => $reservation->id, 'product_variant_id' => $variant->id]);

    app(PrepareFrameReservation::class)->handle($reservation);
})->throws(ValidationException::class);

test('prepare rejects non-requested reservation', function () {
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Prepared]);

    app(PrepareFrameReservation::class)->handle($reservation);
})->throws(ValidationException::class);

test('release restores stock for prepared reservation', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 4]);

    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Prepared]);
    FrameReservationItem::factory()->create(['frame_reservation_id' => $reservation->id, 'product_variant_id' => $variant->id]);

    app(ReleaseFrameReservation::class)->handle($reservation);

    expect($variant->fresh()->stock_quantity)->toBe(5)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Released);
});

test('release is idempotent for non-prepared reservation', function () {
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);

    app(ReleaseFrameReservation::class)->handle($reservation);

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Released);
});

test('release only restores stock once', function () {
    $brand = Brand::factory()->create();
    $frame = Product::factory()->create(['product_type' => 'frame', 'is_active' => true, 'brand_id' => $brand->id]);
    $variant = ProductVariant::factory()->create(['product_id' => $frame->id, 'stock_quantity' => 4]);

    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Prepared]);
    FrameReservationItem::factory()->create(['frame_reservation_id' => $reservation->id, 'product_variant_id' => $variant->id]);

    // Release twice
    app(ReleaseFrameReservation::class)->handle($reservation);
    app(ReleaseFrameReservation::class)->handle($reservation->fresh());

    // Stock should only be restored once (4 -> 5, not 4 -> 6)
    expect($variant->fresh()->stock_quantity)->toBe(5);
});
