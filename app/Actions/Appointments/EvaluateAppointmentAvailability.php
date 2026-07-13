<?php

namespace App\Actions\Appointments;

use App\Models\Appointment;
use App\Models\User;
use App\Models\VisitReason;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EvaluateAppointmentAvailability
{
    public function handle(
        CarbonInterface $startsAt,
        VisitReason $visitReason,
        ?User $optometrist = null,
        ?Appointment $ignoreAppointment = null,
        bool $enforceFuture = true,
        bool $enforceGrid = false,
    ): AppointmentAvailabilityDecision {
        $clinicStartsAt = $startsAt->copy()->setTimezone(config('app.timezone'));
        $endsAt = $clinicStartsAt->copy()->addMinutes($visitReason->duration_minutes);

        if ($enforceFuture && ! $clinicStartsAt->isFuture()) {
            return AppointmentAvailabilityDecision::unavailable($clinicStartsAt, $endsAt, 'elapsed');
        }

        if ($this->isClosed($clinicStartsAt)) {
            return AppointmentAvailabilityDecision::unavailable($clinicStartsAt, $endsAt, 'clinic_closed');
        }

        if (! $this->fitsClinicHours($clinicStartsAt, $endsAt)) {
            return AppointmentAvailabilityDecision::unavailable($clinicStartsAt, $endsAt, 'outside_clinic_hours');
        }

        if ($enforceGrid && ! $this->isOnSlotBoundary($clinicStartsAt)) {
            return AppointmentAvailabilityDecision::unavailable($clinicStartsAt, $endsAt, 'outside_slot_grid');
        }

        $appointments = $this->overlappingAppointments($clinicStartsAt, $endsAt, $ignoreAppointment);
        $capacity = max(1, User::query()->optometrists()->count());

        if ($this->wouldExceedCapacity($clinicStartsAt, $endsAt, $appointments, $capacity, $optometrist)) {
            return AppointmentAvailabilityDecision::unavailable($clinicStartsAt, $endsAt, 'capacity_reached');
        }

        return AppointmentAvailabilityDecision::available($clinicStartsAt, $endsAt);
    }

    private function isClosed(CarbonInterface $startsAt): bool
    {
        return in_array(
            $startsAt->dayOfWeek,
            config('appointments.clinic_hours.closed_weekdays', [0]),
            true,
        );
    }

    private function fitsClinicHours(CarbonInterface $startsAt, CarbonInterface $endsAt): bool
    {
        $openingTime = $startsAt->copy()->startOfDay()->setTimeFromTimeString(
            config('appointments.clinic_hours.opens_at', '09:00'),
        );
        $closingTime = $startsAt->copy()->startOfDay()->setTimeFromTimeString(
            config('appointments.clinic_hours.closes_at', '17:00'),
        );

        return $startsAt->gte($openingTime) && $endsAt->lte($closingTime);
    }

    private function isOnSlotBoundary(CarbonInterface $startsAt): bool
    {
        $openingTime = $startsAt->copy()->startOfDay()->setTimeFromTimeString(
            config('appointments.clinic_hours.opens_at', '09:00'),
        );
        $intervalMinutes = (int) config('appointments.clinic_hours.slot_interval_minutes', 15);

        return $openingTime->diffInMinutes($startsAt, false) % $intervalMinutes === 0;
    }

    /**
     * @return Collection<int, Appointment>
     */
    private function overlappingAppointments(
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?Appointment $ignoreAppointment,
    ): Collection {
        return Appointment::query()
            ->select(['id', 'optometrist_id', 'visit_reason_id', 'appointment_status_id', 'scheduled_at'])
            ->with(['visitReason:id,duration_minutes', 'status:id,name'])
            ->whereHas('status', fn (Builder $query): Builder => $query->whereNotIn('name', ['cancelled', 'no_show']))
            ->when($ignoreAppointment, fn (Builder $query): Builder => $query->whereKeyNot($ignoreAppointment->id))
            ->where('scheduled_at', '<', $endsAt)
            ->whereRaw(
                'DATE_ADD(scheduled_at, INTERVAL COALESCE((SELECT duration_minutes FROM visit_reasons WHERE visit_reasons.id = appointments.visit_reason_id), 30) MINUTE) > ?',
                [$startsAt],
            )
            ->get();
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     */
    private function wouldExceedCapacity(
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        Collection $appointments,
        int $capacity,
        ?User $optometrist,
    ): bool {
        $boundaries = collect([$startsAt->copy(), $endsAt->copy()]);

        $appointments->each(function (Appointment $appointment) use ($boundaries, $startsAt, $endsAt): void {
            $appointmentStartsAt = $appointment->scheduled_at->copy()->setTimezone(config('app.timezone'));
            $appointmentEndsAt = $appointmentStartsAt->copy()->addMinutes(
                $appointment->visitReason?->duration_minutes ?? 30,
            );

            if ($appointmentStartsAt->between($startsAt, $endsAt, false)) {
                $boundaries->push($appointmentStartsAt);
            }

            if ($appointmentEndsAt->between($startsAt, $endsAt, false)) {
                $boundaries->push($appointmentEndsAt);
            }
        });

        $orderedBoundaries = $boundaries
            ->unique(fn (CarbonInterface $boundary): int => $boundary->getTimestamp())
            ->sortBy(fn (CarbonInterface $boundary): int => $boundary->getTimestamp())
            ->values();

        for ($index = 0; $index < $orderedBoundaries->count() - 1; $index++) {
            $segmentStartsAt = $orderedBoundaries[$index];
            $segmentEndsAt = $orderedBoundaries[$index + 1];

            $overlapping = $appointments->filter(
                fn (Appointment $appointment): bool => $this->appointmentOverlapsSegment(
                    appointment: $appointment,
                    segmentStartsAt: $segmentStartsAt,
                    segmentEndsAt: $segmentEndsAt,
                ),
            );

            if ($optometrist !== null && $overlapping->contains(
                fn (Appointment $appointment): bool => $appointment->optometrist_id === $optometrist->id,
            )) {
                return true;
            }

            if ($overlapping->count() >= $capacity) {
                return true;
            }
        }

        return false;
    }

    private function appointmentOverlapsSegment(
        Appointment $appointment,
        CarbonInterface $segmentStartsAt,
        CarbonInterface $segmentEndsAt,
    ): bool {
        $appointmentStartsAt = $appointment->scheduled_at->copy()->setTimezone(config('app.timezone'));
        $appointmentEndsAt = $appointmentStartsAt->copy()->addMinutes(
            $appointment->visitReason?->duration_minutes ?? 30,
        );

        return $appointmentStartsAt->lt($segmentEndsAt) && $appointmentEndsAt->gt($segmentStartsAt);
    }
}
