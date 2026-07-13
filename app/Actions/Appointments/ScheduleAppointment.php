<?php

namespace App\Actions\Appointments;

use App\Models\Appointment;
use App\Models\User;
use App\Models\VisitReason;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class ScheduleAppointment
{
    public function __construct(private readonly EvaluateAppointmentAvailability $evaluateAppointmentAvailability) {}

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
        $endsAt = $scheduledAt->copy()->addMinutes($durationMinutes);
        $decision = $this->evaluateAppointmentAvailability->handle(
            startsAt: $scheduledAt,
            visitReason: new VisitReason(['duration_minutes' => $durationMinutes]),
            enforceFuture: true,
        );

        if ($decision->reason === 'elapsed') {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The appointment must be scheduled in the future.'],
            ]);
        }

        if ($decision->reason === 'clinic_closed') {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The clinic is closed on the selected day.'],
            ]);
        }

        if ($decision->reason === 'outside_clinic_hours' || $endsAt->lte($scheduledAt)) {
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
        $decision = $this->evaluateAppointmentAvailability->handle(
            startsAt: $scheduledAt,
            visitReason: new VisitReason(['duration_minutes' => $durationMinutes]),
            optometrist: $optometrist,
            ignoreAppointment: $ignoreAppointment,
            enforceFuture: false,
        );

        if (! $decision->available) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['This time slot is not available. Please choose another time.'],
            ]);
        }
    }
}
