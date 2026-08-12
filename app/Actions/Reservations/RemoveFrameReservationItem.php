<?php

namespace App\Actions\Reservations;

use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
use Illuminate\Support\Facades\DB;

class RemoveFrameReservationItem
{
    public function handle(FrameReservation $reservation, FrameReservationItem $item): void
    {
        DB::transaction(function () use ($reservation, $item): void {
            $reservation = FrameReservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($reservation->isHeld()) {
                app(FrameReservationStock::class)->release($reservation, $item->product_variant_id);
            }

            $item->delete();

            if ($reservation->items()->count() === 0) {
                app(DeleteFrameReservation::class)->handle($reservation);
            }
        });
    }
}
