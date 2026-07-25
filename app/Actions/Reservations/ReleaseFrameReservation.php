<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

class ReleaseFrameReservation
{
    public function handle(FrameReservation $reservation): FrameReservation
    {
        if ($reservation->status !== ReservationStatus::Prepared) {
            // Only prepared reservations have allocated stock to release
            $reservation->update(['status' => ReservationStatus::Released]);

            return $reservation->fresh();
        }

        return DB::transaction(function () use ($reservation): FrameReservation {
            // Restore stock for each item under a row lock
            foreach ($reservation->items as $item) {
                ProductVariant::query()
                    ->whereKey($item->product_variant_id)
                    ->lockForUpdate()
                    ->increment('stock_quantity');
            }

            $reservation->update(['status' => ReservationStatus::Released]);

            return $reservation->fresh();
        });
    }
}
