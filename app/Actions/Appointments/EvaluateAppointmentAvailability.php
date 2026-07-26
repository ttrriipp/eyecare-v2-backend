<?php

namespace App\Actions\Appointments;

use App\Models\Appointment;
use App\Models\ProviderHour;
use App\Models\ScheduleOverride;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EvaluateAppointmentAvailability
{
    public function handle(
        CarbonInterface $startsAt,
        int $durationMinutes,
        ?User $optometrist = null,
        ?Appointment $ignoreAppointment = null,
        bool $enforceFuture = true,
        bool $enforceGrid = false,
        ?Collection $blockingAppointments = null,
        ?int $capacity = null,
        ?ClinicSchedule $schedule = null,
    ): AppointmentAvailabilityDecision {
        $schedule ??= ClinicSchedule::forDate($startsAt);

        $clinicStartsAt = $startsAt->copy()->setTimezone(config('app.timezone'));
        $endsAt = $clinicStartsAt->copy()->addMinutes($durationMinutes);

        if ($enforceFuture && ! $clinicStartsAt->isFuture()) {
            return AppointmentAvailabilityDecision::unavailable($clinicStartsAt, $endsAt, 'elapsed');
        }

        if ($schedule->isClosed) {
            return AppointmentAvailabilityDecision::unavailable($clinicStartsAt, $endsAt, 'clinic_closed');
        }

        if (! $this->fitsClinicHours($clinicStartsAt, $endsAt, $schedule)) {
            return AppointmentAvailabilityDecision::unavailable($clinicStartsAt, $endsAt, 'outside_clinic_hours');
        }

        if ($enforceGrid && ! $this->isOnSlotBoundary($clinicStartsAt, $schedule)) {
            return AppointmentAvailabilityDecision::unavailable($clinicStartsAt, $endsAt, 'outside_slot_grid');
        }

        $appointments = $blockingAppointments
            ?? $this->blockingAppointmentsBetween($clinicStartsAt, $endsAt, $ignoreAppointment);
        $capacity ??= $this->eligibleOptometristCapacity();

        if ($this->wouldExceedCapacity($clinicStartsAt, $endsAt, $appointments, $capacity, $optometrist)) {
            return AppointmentAvailabilityDecision::unavailable($clinicStartsAt, $endsAt, 'capacity_reached');
        }

        return AppointmentAvailabilityDecision::available($clinicStartsAt, $endsAt);
    }

    private function fitsClinicHours(CarbonInterface $startsAt, CarbonInterface $endsAt, ClinicSchedule $schedule): bool
    {
        $openingTime = $startsAt->copy()->startOfDay()->setTimeFromTimeString($schedule->openTime);
        $closingTime = $startsAt->copy()->startOfDay()->setTimeFromTimeString($schedule->closeTime);

        return $startsAt->gte($openingTime) && $endsAt->lte($closingTime);
    }

    private function isOnSlotBoundary(CarbonInterface $startsAt, ClinicSchedule $schedule): bool
    {
        $openingTime = $startsAt->copy()->startOfDay()->setTimeFromTimeString($schedule->openTime);

        return $openingTime->diffInMinutes($startsAt, false) % $schedule->slotIntervalMinutes === 0;
    }

    /**
     * Count optometrists available for the given date, considering provider hours and absences.
     */
    public function eligibleOptometristCapacity(?CarbonInterface $date = null): int
    {
        if ($date === null) {
            return max(1, User::query()->optometrists()->count());
        }

        $weekday = $date->dayOfWeek;
        $dateString = $date->toDateString();

        // Get optometrists with provider hours for this weekday
        $optometristsWithHours = ProviderHour::query()
            ->where('weekday', $weekday)
            ->where('enabled', true)
            ->pluck('user_id')
            ->toArray();

        // Get optometrists with provider absences for this date
        $absentOptometrists = ScheduleOverride::query()
            ->where('override_date', $dateString)
            ->where('type', ScheduleOverride::TYPE_PROVIDER_ABSENCE)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->toArray();

        // Count available optometrists: has hours for this weekday AND no absence
        $availableCount = count(array_diff($optometristsWithHours, $absentOptometrists));

        return max(1, $availableCount);
    }

    /**
     * @return Collection<int, Appointment>
     */
    public function blockingAppointmentsBetween(
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?Appointment $ignoreAppointment,
    ): Collection {
        return Appointment::query()
            ->select(['id', 'optometrist_id', 'duration_minutes', 'appointment_status_id', 'scheduled_at'])
            ->with(['status:id,name'])
            ->whereHas('status', fn (Builder $query): Builder => $query->whereNotIn('name', ['cancelled', 'no_show']))
            ->when($ignoreAppointment, fn (Builder $query): Builder => $query->whereKeyNot($ignoreAppointment->id))
            ->where('scheduled_at', '<', $endsAt)
            ->whereRaw(
                'DATE_ADD(scheduled_at, INTERVAL COALESCE(duration_minutes, 30) MINUTE) > ?',
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
                $appointment->duration_minutes ?? 30,
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
            $appointment->duration_minutes ?? 30,
        );

        return $appointmentStartsAt->lt($segmentEndsAt) && $appointmentEndsAt->gt($segmentStartsAt);
    }
}
