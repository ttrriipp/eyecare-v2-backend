<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrepareFrameReservation
{
    public function handle(FrameReservation $reservation): FrameReservation
    {
        if ($reservation->status !== ReservationStatus::Requested) {
            throw ValidationException::withMessages([
                'reservation' => ['Only requested reservations can be prepared.'],
            ]);
        }

        return DB::transaction(function () use ($reservation): FrameReservation {
            // Allocate stock for each item under a row lock
            foreach ($reservation->items as $item) {
                $variant = ProductVariant::query()
                    ->whereKey($item->product_variant_id)
                    ->lockForUpdate()
                    ->first();

                if ($variant === null || $variant->stock_quantity < 1) {
                    throw ValidationException::withMessages([
                        'items' => ["Variant {$item->product_variant_id} is no longer available."],
                    ]);
                }

                $variant->decrement('stock_quantity');
            }

            $reservation->update(['status' => ReservationStatus::Prepared]);

            return $reservation->fresh();
        });
    }
}
