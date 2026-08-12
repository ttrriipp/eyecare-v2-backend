<?php

namespace App\Actions\Reservations;

use App\Models\FrameReservation;
use Illuminate\Support\Facades\DB;

class DeleteFrameReservation
{
    public function handle(FrameReservation $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            $locked = FrameReservation::query()
                ->lockForUpdate()
                ->find($reservation->id);

            if ($locked === null) {
                return;
            }

            if ($locked->isHeld()) {
                $stock = app(FrameReservationStock::class);

                foreach ($locked->items as $item) {
                    $stock->release($locked, $item->product_variant_id);
                }
            }

            $locked->items()->delete();
            $locked->delete();
        });
    }
}
