<?php

namespace App\Actions\Appointments;

use App\Enums\AppointmentStatusName;
use App\Models\Appointment;
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
        ?bool $providerAvailable = null,
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

        if ($optometrist !== null) {
            if (! $this->isOptometristEligible($optometrist, $clinicStartsAt, $endsAt)) {
                return AppointmentAvailabilityDecision::unavailable($clinicStartsAt, $endsAt, 'capacity_reached');
            }
        } else {
            $providerAvailable ??= $this->hasEligibleOptometrist($clinicStartsAt, $endsAt);

            if (! $providerAvailable) {
                return AppointmentAvailabilityDecision::unavailable($clinicStartsAt, $endsAt, 'capacity_reached');
            }
        }

        if ($this->hasOverlappingAppointment($clinicStartsAt, $endsAt, $appointments)) {
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
     * Determine whether at least one active optometrist can cover the exact interval.
     */
    public function hasEligibleOptometrist(
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): bool {
        $optometrists = User::query()->optometrists()->get();

        if ($optometrists->isEmpty()) {
            return false;
        }

        $dateString = $startsAt->toDateString();

        // Get optometrists with provider absences for this date
        $absences = ScheduleOverride::query()
            ->where('override_date', $dateString)
            ->where('type', ScheduleOverride::TYPE_PROVIDER_ABSENCE)
            ->whereNotNull('user_id')
            ->get()
            ->keyBy('user_id');

        return $optometrists->contains(
            fn (User $optometrist): bool => $this->isProviderAvailableForInterval(
                $optometrist,
                $startsAt,
                $endsAt,
                $absences,
            ),
        );
    }

    /**
     * Check if a specific optometrist is eligible for the exact interval.
     */
    public function isOptometristEligible(
        User $optometrist,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): bool {
        if (! $optometrist->is_active || ! $optometrist->isOptometrist()) {
            return false;
        }

        $dateString = $startsAt->toDateString();

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
            ->whereHas('status', fn (Builder $query): Builder => $query->whereIn('name', [
                AppointmentStatusName::Scheduled->value,
                AppointmentStatusName::CheckedIn->value,
            ]))
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
    private function hasOverlappingAppointment(
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        Collection $appointments,
    ): bool {
        return $appointments->contains(
            fn (Appointment $appointment): bool => $this->appointmentOverlaps(
                appointment: $appointment,
                startsAt: $startsAt,
                endsAt: $endsAt,
            ),
        );
    }

    /**
     * @param  Collection<int, ScheduleOverride>  $absences
     */
    private function isProviderAvailableForInterval(
        User $optometrist,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        Collection $absences,
    ): bool {
        $absence = $absences->get($optometrist->id);

        if ($absence === null) {
            return true;
        }

        if ($absence->start_time === null && $absence->end_time === null) {
            return false;
        }

        if ($absence->start_time === null || $absence->end_time === null) {
            return true;
        }

        $absenceStart = $startsAt->copy()->startOfDay()->setTimeFromTimeString(self::timeString($absence->start_time));
        $absenceEnd = $startsAt->copy()->startOfDay()->setTimeFromTimeString(self::timeString($absence->end_time));

        return ! ($startsAt->lt($absenceEnd) && $endsAt->gt($absenceStart));
    }

    private function appointmentOverlaps(
        Appointment $appointment,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): bool {
        $appointmentStartsAt = $appointment->scheduled_at->copy()->setTimezone(config('app.timezone'));
        $appointmentEndsAt = $appointmentStartsAt->copy()->addMinutes(
            $appointment->duration_minutes ?? 30,
        );

        return $appointmentStartsAt->lt($endsAt) && $appointmentEndsAt->gt($startsAt);
    }
}
