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

        // Use Released when appointment is still active, Cancelled when appointment is cancelled
        $isAppointmentCancelled = $appointment->status?->name === 'cancelled';
        $targetStatus = $isAppointmentCancelled ? ReservationStatus::Cancelled : ReservationStatus::Released;

        foreach ($reservations as $reservation) {
            $releaseAction->handle($reservation, $targetStatus);
        }
    }
}
