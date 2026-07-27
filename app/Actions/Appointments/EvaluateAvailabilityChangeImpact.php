<?php

namespace App\Actions\Appointments;

use App\Models\Appointment;
use App\Models\ClinicHour;
use Illuminate\Support\Collection;

/**
 * Read-only evaluator that reports future appointments affected by
 * proposed clinic hours, provider hours, closures, early closing,
 * or provider absence changes.
 *
 * Never mutates availability or appointments.
 */
class EvaluateAvailabilityChangeImpact
{
    public function __construct(
        private readonly EvaluateAppointmentAvailability $evaluator,
    ) {}

    /**
     * Evaluate the impact of a proposed clinic hour change.
     *
     * @return Collection<int, Appointment>
     */
    public function clinicHourChange(
        int $weekday,
        ?string $newOpenTime,
        ?string $newCloseTime,
        ?bool $newEnabled,
    ): Collection {
        $currentHour = ClinicHour::query()
            ->where('weekday', $weekday)
            ->first();

        // Get future appointments on this weekday
        $appointments = $this->futureAppointmentsOnWeekday($weekday);

        if ($appointments->isEmpty()) {
            return collect();
        }

        // Check each appointment against the new hours
        return $appointments->filter(function (Appointment $appointment) use ($newOpenTime, $newCloseTime, $newEnabled) {
            if ($newEnabled === false) {
                return true; // Clinic closed = all appointments affected
            }

            $startsAt = $appointment->scheduled_at->copy()->setTimezone(config('app.timezone'));
            $endsAt = $startsAt->copy()->addMinutes($appointment->duration_minutes ?? 30);

            if ($newOpenTime === null || $newCloseTime === null) {
                return false; // Can't evaluate without times
            }

            $openTime = $startsAt->copy()->startOfDay()->setTimeFromTimeString($newOpenTime);
            $closeTime = $startsAt->copy()->startOfDay()->setTimeFromTimeString($newCloseTime);

            return $startsAt->lt($openTime) || $endsAt->gt($closeTime);
        });
    }

    /**
     * Evaluate the impact of a proposed provider hour change.
     *
     * @return Collection<int, Appointment>
     */
    public function providerHourChange(
        int $userId,
        int $weekday,
        ?string $newStartTime,
        ?string $newEndTime,
        ?bool $newEnabled,
    ): Collection {
        // Get future appointments assigned to this provider on this weekday
        $appointments = $this->futureAppointmentsOnWeekday($weekday)
            ->where('optometrist_id', $userId);

        if ($appointments->isEmpty()) {
            return collect();
        }

        return $appointments->filter(function (Appointment $appointment) use ($newStartTime, $newEndTime, $newEnabled) {
            if ($newEnabled === false) {
                return true; // Provider disabled = all their appointments affected
            }

            $startsAt = $appointment->scheduled_at->copy()->setTimezone(config('app.timezone'));
            $endsAt = $startsAt->copy()->addMinutes($appointment->duration_minutes ?? 30);

            if ($newStartTime === null || $newEndTime === null) {
                return false;
            }

            $providerStart = $startsAt->copy()->startOfDay()->setTimeFromTimeString($newStartTime);
            $providerEnd = $startsAt->copy()->startOfDay()->setTimeFromTimeString($newEndTime);

            return $startsAt->lt($providerStart) || $endsAt->gt($providerEnd);
        });
    }

    /**
     * Evaluate the impact of a clinic closure on a specific date.
     *
     * @return Collection<int, Appointment>
     */
    public function clinicClosure(string $date): Collection
    {
        return $this->futureAppointmentsOnDate($date);
    }

    /**
     * Evaluate the impact of an early closing on a specific date.
     *
     * @return Collection<int, Appointment>
     */
    public function earlyClosing(string $date, string $endTime): Collection
    {
        return $this->futureAppointmentsOnDate($date)->filter(
            function (Appointment $appointment) use ($endTime): bool {
                $startsAt = $appointment->scheduled_at->copy()->setTimezone(config('app.timezone'));
                $endsAt = $startsAt->copy()->addMinutes($appointment->duration_minutes ?? 30);
                $earlyClose = $startsAt->copy()->startOfDay()->setTimeFromTimeString($endTime);

                return $endsAt->gt($earlyClose);
            },
        );
    }

    /**
     * Evaluate the impact of a provider absence on a specific date.
     *
     * @return Collection<int, Appointment>
     */
    public function providerAbsence(int $userId, string $date, ?string $startTime = null, ?string $endTime = null): Collection
    {
        $appointments = $this->futureAppointmentsOnDate($date)
            ->where('optometrist_id', $userId);

        if ($appointments->isEmpty()) {
            return collect();
        }

        // Full-day absence
        if ($startTime === null && $endTime === null) {
            return $appointments;
        }

        // Partial absence
        return $appointments->filter(function (Appointment $appointment) use ($startTime, $endTime) {
            $startsAt = $appointment->scheduled_at->copy()->setTimezone(config('app.timezone'));
            $endsAt = $startsAt->copy()->addMinutes($appointment->duration_minutes ?? 30);

            $absenceStart = $startsAt->copy()->startOfDay()->setTimeFromTimeString($startTime);
            $absenceEnd = $startsAt->copy()->startOfDay()->setTimeFromTimeString($endTime);

            return $startsAt->lt($absenceEnd) && $endsAt->gt($absenceStart);
        });
    }

    /**
     * Get future appointments on a specific weekday (0-6).
     *
     * @return Collection<int, Appointment>
     */
    private function futureAppointmentsOnWeekday(int $weekday): Collection
    {
        return Appointment::query()
            ->with(['patient', 'appointmentType', 'status'])
            ->whereHas('status', fn ($q) => $q->whereIn('name', ['scheduled', 'checked_in']))
            ->where('scheduled_at', '>', now())
            ->whereRaw('WEEKDAY(scheduled_at) = ?', [$weekday])
            ->get();
    }

    /**
     * Get future appointments on a specific date.
     *
     * @return Collection<int, Appointment>
     */
    private function futureAppointmentsOnDate(string $date): Collection
    {
        return Appointment::query()
            ->with(['patient', 'appointmentType', 'status'])
            ->whereHas('status', fn ($q) => $q->whereIn('name', ['scheduled', 'checked_in']))
            ->whereDate('scheduled_at', $date)
            ->where('scheduled_at', '>', now())
            ->get();
    }
}
