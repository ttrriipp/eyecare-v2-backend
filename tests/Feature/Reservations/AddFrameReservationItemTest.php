<?php

use App\Actions\Reservations\AddFrameReservationItem;
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

test('adds a frame to an unaccepted reservation without touching stock', function () {
    $variant = createActiveFrameVariantForAdd()->fresh(['product']);
    $variant->update(['stock_quantity' => 10]);
    $reservation = FrameReservation::factory()->create();

    $item = app(AddFrameReservationItem::class)->handle($reservation, $variant->id);

    expect($item->product_variant_id)->toBe($variant->id)
        ->and($variant->fresh()->stock_quantity)->toBe(10)
        ->and($reservation->items()->count())->toBe(1);
});

test('adding a frame to an accepted reservation allocates its stock immediately', function () {
    $variant = createActiveFrameVariantForAdd();
    $variant->update(['stock_quantity' => 10]);
    $reservation = FrameReservation::factory()->accepted()->create();

    app(AddFrameReservationItem::class)->handle($reservation, $variant->id);

    expect($variant->fresh()->stock_quantity)->toBe(9);
});

test('the same variant cannot be added twice to one reservation', function () {
    $variant = createActiveFrameVariantForAdd();
    $reservation = FrameReservation::factory()->create();
    $reservation->items()->create(['product_variant_id' => $variant->id]);

    app(AddFrameReservationItem::class)->handle($reservation, $variant->id);
})->throws(ValidationException::class, 'already part of the reservation');

test('a non-frame variant cannot be added', function () {
    $variant = ProductVariant::factory()->create([
        'is_active' => true,
        'product_id' => Product::factory()->create(['product_type' => 'accessory', 'is_active' => true])->id,
    ]);
    $reservation = FrameReservation::factory()->create();

    app(AddFrameReservationItem::class)->handle($reservation, $variant->id);
})->throws(ValidationException::class, 'not an active frame variant');
