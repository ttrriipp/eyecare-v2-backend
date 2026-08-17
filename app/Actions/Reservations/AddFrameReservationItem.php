<?php

namespace App\Actions\Reservations;

use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddFrameReservationItem
{
    public function handle(FrameReservation $reservation, int $productVariantId): FrameReservationItem
    {
        $this->validateVariant($reservation, $productVariantId);

        return DB::transaction(function () use ($reservation, $productVariantId): FrameReservationItem {
            $reservation = FrameReservation::query()->lockForUpdate()->findOrFail($reservation->id);

            $item = FrameReservationItem::query()->create([
                'frame_reservation_id' => $reservation->id,
                'product_variant_id' => $productVariantId,
            ]);

            if ($reservation->isHeld()) {
                app(FrameReservationStock::class)->allocate($reservation, $productVariantId);
            }

            return $item->fresh();
        });
    }

    private function validateVariant(FrameReservation $reservation, int $productVariantId): void
    {
        $currentCount = $reservation->items()->count();

        if ($currentCount >= 3) {
            throw ValidationException::withMessages([
                'product_variant_id' => ['A reservation cannot have more than 3 frame candidates.'],
            ]);
        }

        $alreadyIncluded = $reservation->items()
            ->where('product_variant_id', $productVariantId)
            ->exists();

        if ($alreadyIncluded) {
            throw ValidationException::withMessages([
                'product_variant_id' => ['This variant is already part of the reservation.'],
            ]);
        }

        $variant = ProductVariant::query()->active()->with('product')->find($productVariantId);
        $product = $variant?->product;

        if ($variant === null || $product === null || $product->product_type !== 'frame') {
            throw ValidationException::withMessages([
                'product_variant_id' => ['This variant is not an active frame variant.'],
            ]);
        }
    }
}
