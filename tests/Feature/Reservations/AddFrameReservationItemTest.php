<?php

use App\Actions\Reservations\AddFrameReservationItem;
use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

function createActiveFrameVariantForAdd(): ProductVariant
{
    return ProductVariant::factory()->create([
        'is_active' => true,
        'product_id' => Product::factory()->create(['product_type' => 'frame', 'is_active' => true])->id,
    ]);
}

test('adds a frame to a requested reservation without touching stock', function () {
    $variant = createActiveFrameVariantForAdd()->fresh(['product']);
    $variant->update(['stock_quantity' => 10]);
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);

    $item = app(AddFrameReservationItem::class)->handle($reservation, $variant->id);

    expect($item->product_variant_id)->toBe($variant->id)
        ->and($variant->fresh()->stock_quantity)->toBe(10)
        ->and($reservation->items()->count())->toBe(1);
});

test('adding a frame to a prepared reservation allocates its stock immediately', function () {
    $variant = createActiveFrameVariantForAdd();
    $variant->update(['stock_quantity' => 10]);
    $reservation = FrameReservation::factory()->prepared()->create();

    app(AddFrameReservationItem::class)->handle($reservation, $variant->id);

    expect($variant->fresh()->stock_quantity)->toBe(9);
});

test('the same variant cannot be added twice to one reservation', function () {
    $variant = createActiveFrameVariantForAdd();
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);
    $reservation->items()->create(['product_variant_id' => $variant->id]);

    app(AddFrameReservationItem::class)->handle($reservation, $variant->id);
})->throws(ValidationException::class, 'already part of the reservation');

test('a non-frame variant cannot be added', function () {
    $variant = ProductVariant::factory()->create([
        'is_active' => true,
        'product_id' => Product::factory()->create(['product_type' => 'accessory', 'is_active' => true])->id,
    ]);
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);

    app(AddFrameReservationItem::class)->handle($reservation, $variant->id);
})->throws(ValidationException::class, 'not an active frame variant');

test('frames cannot be added to a tried-on reservation', function () {
    $variant = createActiveFrameVariantForAdd();
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::TriedOn]);

    app(AddFrameReservationItem::class)->handle($reservation, $variant->id);
})->throws(ValidationException::class, 'Frames can only be added');

test('frames cannot be added to a converted reservation', function () {
    $variant = createActiveFrameVariantForAdd();
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Converted]);

    app(AddFrameReservationItem::class)->handle($reservation, $variant->id);
})->throws(ValidationException::class, 'Frames can only be added');
