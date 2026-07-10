<?php

namespace App\Actions\Appointments;

use App\Models\Appointment;
use App\Models\User;
use App\Models\VisitReason;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class ScheduleAppointment
{
    public function handle(
        CarbonInterface $scheduledAt,
        VisitReason $visitReason,
        ?User $optometrist = null,
        ?Appointment $ignoreAppointment = null,
    ): void {
        $this->validateOptometrist($optometrist);
        $this->validateClinicHours($scheduledAt, $visitReason->duration_minutes);
        $this->validateAvailability($scheduledAt, $visitReason->duration_minutes, $optometrist, $ignoreAppointment);
    }

    private function validateOptometrist(?User $optometrist): void
    {
        if ($optometrist === null) {
            return;
        }

        $isEligible = User::query()->optometrists()->whereKey($optometrist)->exists();

        if (! $isEligible) {
            throw ValidationException::withMessages([
                'optometrist_id' => ['The selected user is not an optometrist.'],
            ]);
        }
    }

    private function validateClinicHours(CarbonInterface $scheduledAt, int $durationMinutes): void
    {
        if ($scheduledAt->isPast()) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The appointment must be scheduled in the future.'],
            ]);
        }

        $closedWeekdays = config('appointments.clinic_hours.closed_weekdays', [0]);

        if (in_array($scheduledAt->dayOfWeek, $closedWeekdays, true)) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The clinic is closed on the selected day.'],
            ]);
        }

        $openingTime = $scheduledAt->copy()->startOfDay()->setTimeFromTimeString(
            config('appointments.clinic_hours.opens_at', '09:00'),
        );
        $closingTime = $scheduledAt->copy()->startOfDay()->setTimeFromTimeString(
            config('appointments.clinic_hours.closes_at', '17:00'),
        );
        $appointmentEndsAt = $scheduledAt->copy()->addMinutes($durationMinutes);

        if ($scheduledAt->lt($openingTime) || $appointmentEndsAt->gt($closingTime)) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The appointment must fit within clinic hours.'],
            ]);
        }
    }

    private function validateAvailability(
        CarbonInterface $scheduledAt,
        int $durationMinutes,
        ?User $optometrist,
        ?Appointment $ignoreAppointment,
    ): void {
        $appointmentEndsAt = $scheduledAt->copy()->addMinutes($durationMinutes);

        $conflictingAppointments = Appointment::query()
            ->whereHas('status', fn ($query) => $query->whereNotIn('name', ['cancelled', 'no_show']))
            ->when($ignoreAppointment, fn ($query) => $query->whereKeyNot($ignoreAppointment->id))
            ->where('scheduled_at', '<', $appointmentEndsAt)
            ->whereRaw(
                'DATE_ADD(scheduled_at, INTERVAL COALESCE((SELECT duration_minutes FROM visit_reasons WHERE visit_reasons.id = appointments.visit_reason_id), 30) MINUTE) > ?',
                [$scheduledAt],
            );

        $hasConflict = $optometrist
            ? $conflictingAppointments->where('optometrist_id', $optometrist->id)->exists()
            : $conflictingAppointments->count() >= max(1, User::query()->optometrists()->count());

        if ($hasConflict) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['This time slot is not available. Please choose another time.'],
            ]);
        }
    }
}
