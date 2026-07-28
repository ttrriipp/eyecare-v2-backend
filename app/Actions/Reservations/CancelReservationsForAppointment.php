<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Appointment;
use App\Models\FrameReservation;

class CancelReservationsForAppointment
{
    public function handle(Appointment $appointment): void
    {
        $activeStatuses = [
            ReservationStatus::Requested,
            ReservationStatus::Prepared,
            ReservationStatus::TriedOn,
        ];

        $reservations = FrameReservation::query()
            ->where('appointment_id', $appointment->id)
            ->whereIn('status', $activeStatuses)
            ->get();

        if ($reservations->isEmpty()) {
            return;
        }

        $releaseAction = app(ReleaseFrameReservation::class);

        foreach ($reservations as $reservation) {
            // Prepared reservations have allocated stock that must be released.
            if ($reservation->status === ReservationStatus::Prepared) {
                $releaseAction->handle($reservation);
            }

            $reservation->update(['status' => ReservationStatus::Cancelled]);
        }
    }
}
