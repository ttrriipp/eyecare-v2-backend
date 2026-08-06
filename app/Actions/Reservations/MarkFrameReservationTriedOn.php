<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use Illuminate\Validation\ValidationException;

class MarkFrameReservationTriedOn
{
    /**
     * Only a prepared reservation has its stock already allocated — trying
     * on a frame that was never prepared would mean nothing is actually
     * holding that stock for this patient during the try-on.
     */
    public function handle(FrameReservation $reservation): FrameReservation
    {
        if ($reservation->status !== ReservationStatus::Prepared) {
            throw ValidationException::withMessages([
                'reservation' => ['Only prepared reservations can be marked as tried on.'],
            ]);
        }

        $reservation->update(['status' => ReservationStatus::TriedOn]);

        return $reservation->fresh();
    }
}
