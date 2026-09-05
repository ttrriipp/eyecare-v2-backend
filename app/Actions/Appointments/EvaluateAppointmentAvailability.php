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
    /**
     * Extract a time string (H:i) from a value that may be a DateTime or a plain string.
     */
    private static function timeString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        return (string) $value;
    }

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

        // If a specific optometrist is assigned, check their eligibility directly
        if ($optometrist !== null) {
            if (! $this->isOptometristEligible($optometrist, $clinicStartsAt, $endsAt)) {
                return AppointmentAvailabilityDecision::unavailable($clinicStartsAt, $endsAt, 'capacity_reached');
            }
        }

        $capacity ??= $this->eligibleOptometristCapacity($clinicStartsAt, $endsAt);

        if ($this->wouldExceedCapacity($clinicStartsAt, $endsAt, $appointments, $capacity, $optometrist)) {
            return AppointmentAvailabilityDecision::unavailable($clinicStartsAt, $endsAt, 'capacity_reached');
        }

        return AppointmentAvailabilityDecision::available($clinicStartsAt, $endsAt);
    }

    private function fitsClinicHours(CarbonInterface $startsAt, CarbonInterface $endsAt, ClinicSchedule $schedule): bool
    {
        $openingTime = $startsAt->copy()->startOfDay()->setTimeFromTimeString(self::timeString($schedule->openTime));
        $closingTime = $startsAt->copy()->startOfDay()->setTimeFromTimeString(self::timeString($schedule->closeTime));

        return $startsAt->gte($openingTime) && $endsAt->lte($closingTime);
    }

    private function isOnSlotBoundary(CarbonInterface $startsAt, ClinicSchedule $schedule): bool
    {
        $openingTime = $startsAt->copy()->startOfDay()->setTimeFromTimeString(self::timeString($schedule->openTime));

        return $openingTime->diffInMinutes($startsAt, false) % $schedule->slotIntervalMinutes === 0;
    }

    /**
     * Count optometrists available for the exact interval, considering provider hours and absences.
     *
     * An optometrist is eligible only when:
     * - the account has optometrist capability;
     * - an enabled provider-hour row exists for the weekday;
     * - the full candidate interval fits within the provider's start/end time; and
     * - no full-day or overlapping partial absence exists.
     */
    public function eligibleOptometristCapacity(
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $endsAt = null,
    ): int {
        $optometrists = User::query()->optometrists()->get();

        if ($optometrists->isEmpty()) {
            return 0;
        }

        if ($startsAt === null) {
            return $optometrists->count();
        }

        // If only date provided (no end time), count optometrists with hours for this weekday
        // (backward compatibility with tests and callers that pass just a date)
        if ($endsAt === null) {
            $weekday = $startsAt->dayOfWeek;

            $providerHours = ProviderHour::query()
                ->where('weekday', $weekday)
                ->where('enabled', true)
                ->get()
                ->keyBy('user_id');

            $absences = ScheduleOverride::query()
                ->where('override_date', $startsAt->toDateString())
                ->where('type', ScheduleOverride::TYPE_PROVIDER_ABSENCE)
                ->whereNotNull('user_id')
                ->get()
                ->keyBy('user_id');

            $eligibleCount = 0;

            foreach ($optometrists as $optometrist) {
                if (! $providerHours->has($optometrist->id)) {
                    continue;
                }

                if ($absences->has($optometrist->id)) {
                    $absence = $absences->get($optometrist->id);
                    if ($absence->start_time === null && $absence->end_time === null) {
                        continue; // Full-day absence
                    }
                }

                $eligibleCount++;
            }

            return $eligibleCount;
        }

        $weekday = $startsAt->dayOfWeek;
        $dateString = $startsAt->toDateString();

        // Get optometrists with provider hours for this weekday
        $providerHours = ProviderHour::query()
            ->where('weekday', $weekday)
            ->where('enabled', true)
            ->get()
            ->keyBy('user_id');

        // Get optometrists with provider absences for this date
        $absences = ScheduleOverride::query()
            ->where('override_date', $dateString)
            ->where('type', ScheduleOverride::TYPE_PROVIDER_ABSENCE)
            ->whereNotNull('user_id')
            ->get()
            ->keyBy('user_id');

        $eligibleCount = 0;

        foreach ($optometrists as $optometrist) {
            $hours = $providerHours->get($optometrist->id);

            if ($hours === null) {
                continue; // No provider hours for this weekday
            }

            // Check if the full interval fits within provider hours
            $providerStart = $startsAt->copy()->startOfDay()->setTimeFromTimeString(self::timeString($hours->start_time));
            $providerEnd = $startsAt->copy()->startOfDay()->setTimeFromTimeString(self::timeString($hours->end_time));

            if ($startsAt->lt($providerStart) || $endsAt->gt($providerEnd)) {
                continue; // Interval doesn't fit within provider hours
            }

            // Check for absences
            $absence = $absences->get($optometrist->id);

            if ($absence !== null) {
                // Full-day absence (both times null)
                if ($absence->start_time === null && $absence->end_time === null) {
                    continue;
                }

                // Partial absence overlap
                if ($absence->start_time !== null && $absence->end_time !== null) {
                    $absenceStart = $startsAt->copy()->startOfDay()->setTimeFromTimeString(self::timeString($absence->start_time));
                    $absenceEnd = $startsAt->copy()->startOfDay()->setTimeFromTimeString(self::timeString($absence->end_time));

                    if ($startsAt->lt($absenceEnd) && $endsAt->gt($absenceStart)) {
                        continue; // Interval overlaps with absence
                    }
                }
            }

            $eligibleCount++;
        }

        return $eligibleCount;
    }

    /**
     * Calculate the remaining clinic capacity for a candidate interval.
     *
     * The total is the number of active optometrists who can cover the full
     * interval. Existing active appointments reduce that total according to
     * the maximum simultaneous overlap within the interval.
     *
     * @return array{available: int, total: int}
     */
    public function clinicCapacityForInterval(
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?Appointment $ignoreAppointment = null,
        ?Collection $blockingAppointments = null,
    ): array {
        $total = $this->eligibleOptometristCapacity($startsAt, $endsAt);
        $appointments = $blockingAppointments
            ?? $this->blockingAppointmentsBetween($startsAt, $endsAt, $ignoreAppointment);
        $used = $this->maximumConcurrentAppointments($startsAt, $endsAt, $appointments);

        return [
            'available' => max(0, $total - $used),
            'total' => $total,
        ];
    }

    /**
     * Check if a specific optometrist is eligible for the exact interval.
     */
    public function isOptometristEligible(
        User $optometrist,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): bool {
        if (! $optometrist->isOptometrist()) {
            return false;
        }

        $weekday = $startsAt->dayOfWeek;
        $dateString = $startsAt->toDateString();

        $hours = ProviderHour::query()
            ->where('user_id', $optometrist->id)
            ->where('weekday', $weekday)
            ->where('enabled', true)
            ->first();

        if ($hours === null) {
            return false;
        }

        // Check if interval fits within provider hours
        $providerStart = $startsAt->copy()->startOfDay()->setTimeFromTimeString(self::timeString($hours->start_time));
        $providerEnd = $startsAt->copy()->startOfDay()->setTimeFromTimeString(self::timeString($hours->end_time));

        if ($startsAt->lt($providerStart) || $endsAt->gt($providerEnd)) {
            return false;
        }

        // Check for absences
        $absence = ScheduleOverride::query()
            ->where('user_id', $optometrist->id)
            ->where('override_date', $dateString)
            ->where('type', ScheduleOverride::TYPE_PROVIDER_ABSENCE)
            ->first();

        if ($absence !== null) {
            if ($absence->start_time === null && $absence->end_time === null) {
                return false; // Full-day absence
            }

            if ($absence->start_time !== null && $absence->end_time !== null) {
                $absenceStart = $startsAt->copy()->startOfDay()->setTimeFromTimeString(self::timeString($absence->start_time));
                $absenceEnd = $startsAt->copy()->startOfDay()->setTimeFromTimeString(self::timeString($absence->end_time));

                if ($startsAt->lt($absenceEnd) && $endsAt->gt($absenceStart)) {
                    return false;
                }
            }
        }

        return true;
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
        foreach ($this->capacitySegments($startsAt, $endsAt, $appointments) as $overlapping) {
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

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return \Generator<int, Collection<int, Appointment>>
     */
    private function capacitySegments(
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        Collection $appointments,
    ): \Generator {
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

            yield $appointments->filter(
                fn (Appointment $appointment): bool => $this->appointmentOverlapsSegment(
                    appointment: $appointment,
                    segmentStartsAt: $segmentStartsAt,
                    segmentEndsAt: $segmentEndsAt,
                ),
            );
        }
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     */
    private function maximumConcurrentAppointments(
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        Collection $appointments,
    ): int {
        $maximum = 0;

        foreach ($this->capacitySegments($startsAt, $endsAt, $appointments) as $overlapping) {
            $maximum = max($maximum, $overlapping->count());
        }

        return $maximum;
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
