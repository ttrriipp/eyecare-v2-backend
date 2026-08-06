<?php

use App\Actions\Reservations\RemoveFrameReservationItem;
use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

function createFrameVariantForRemoval(): ProductVariant
{
    return ProductVariant::factory()->create([
        'is_active' => true,
        'product_id' => Product::factory()->create(['product_type' => 'frame', 'is_active' => true])->id,
    ]);
}

test('removes an item from a requested reservation without touching stock', function () {
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);
    $keptVariant = createFrameVariantForRemoval();
    $keptVariant->update(['stock_quantity' => 10]);
    $removedVariant = createFrameVariantForRemoval();
    $removedVariant->update(['stock_quantity' => 10]);
    $reservation->items()->create(['product_variant_id' => $keptVariant->id]);
    $removedItem = $reservation->items()->create(['product_variant_id' => $removedVariant->id]);

    app(RemoveFrameReservationItem::class)->handle($reservation, $removedItem);

    expect($reservation->items()->count())->toBe(1)
        ->and($reservation->items()->where('product_variant_id', $keptVariant->id)->exists())->toBeTrue()
        ->and($removedVariant->fresh()->stock_quantity)->toBe(10);
});

test('removing an item from a prepared reservation restores its allocated stock', function () {
    $reservation = FrameReservation::factory()->prepared()->create();
    $keptVariant = createFrameVariantForRemoval();
    $keptVariant->update(['stock_quantity' => 10]);
    $removedVariant = createFrameVariantForRemoval();
    $removedVariant->update(['stock_quantity' => 9]);
    $reservation->items()->create(['product_variant_id' => $keptVariant->id]);
    $removedItem = $reservation->items()->create(['product_variant_id' => $removedVariant->id]);

    app(RemoveFrameReservationItem::class)->handle($reservation, $removedItem);

    expect($removedVariant->fresh()->stock_quantity)->toBe(10)
        ->and($reservation->items()->where('product_variant_id', $keptVariant->id)->exists())->toBeTrue();
});

test('removing the last item releases the reservation', function () {
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::Requested]);
    $variant = createFrameVariantForRemoval();
    $item = $reservation->items()->create(['product_variant_id' => $variant->id]);

    app(RemoveFrameReservationItem::class)->handle($reservation, $item);

    expect($reservation->items()->count())->toBe(0)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Released);
});

test('items cannot be removed from a tried-on reservation', function () {
    $reservation = FrameReservation::factory()->create(['status' => ReservationStatus::TriedOn]);
    $item = $reservation->items()->create(['product_variant_id' => createFrameVariantForRemoval()->id]);
    $reservation->items()->create(['product_variant_id' => createFrameVariantForRemoval()->id]);

    app(RemoveFrameReservationItem::class)->handle($reservation, $item);
})->throws(ValidationException::class, 'Frames can only be removed');
