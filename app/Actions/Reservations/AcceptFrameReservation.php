<?php

namespace App\Actions\Reservations;

use App\Models\FrameReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptFrameReservation
{
    public const RESERVATION_WINDOW_DAYS = 7;

    public function handle(FrameReservation $reservation): FrameReservation
    {
        if ($reservation->isHeld()) {
            return $reservation;
        }

        $appointment = $reservation->appointment;

        if ($appointment === null || $appointment->status->name !== 'scheduled') {
            throw ValidationException::withMessages([
                'reservation' => ['The appointment is no longer scheduled.'],
            ]);
        }

        $endTime = $appointment->scheduled_at->addMinutes($appointment->duration_minutes ?? 30);

        if ($endTime->isPast()) {
            throw ValidationException::withMessages([
                'reservation' => ['The appointment has already ended.'],
            ]);
        }

        $daysUntilAppointment = now()->diffInDays($appointment->scheduled_at, false);

        if ($daysUntilAppointment > self::RESERVATION_WINDOW_DAYS) {
            throw ValidationException::withMessages([
                'reservation' => ['Reservations can only be accepted within '.self::RESERVATION_WINDOW_DAYS.' days of the appointment.'],
            ]);
        }

        return DB::transaction(function () use ($reservation): FrameReservation {
            $locked = FrameReservation::query()
                ->lockForUpdate()
                ->find($reservation->id);

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'reservation' => ['The reservation no longer exists.'],
                ]);
            }

            if ($locked->isHeld()) {
                return $locked->fresh();
            }

            $stock = app(FrameReservationStock::class);

            foreach ($locked->items as $item) {
                $stock->allocate($locked, $item->product_variant_id);
            }

            $locked->update(['accepted_at' => now()]);

            return $locked->fresh();
        });
    }
}
