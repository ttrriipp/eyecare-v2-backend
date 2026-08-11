<?php

namespace App\Actions\Appointments;

use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class ScheduleAppointment
{
    public function __construct(private readonly EvaluateAppointmentAvailability $evaluateAppointmentAvailability) {}

    public function handle(
        CarbonInterface $scheduledAt,
        int $durationMinutes,
        ?User $optometrist = null,
        ?Appointment $ignoreAppointment = null,
        bool $enforceGrid = false,
    ): void {
        if ($durationMinutes < 5 || $durationMinutes > 240 || $durationMinutes % 5 !== 0) {
            throw ValidationException::withMessages([
                'duration_minutes' => ['Duration must be between 5 and 240 minutes in 5-minute increments.'],
            ]);
        }

        $this->validateOptometrist($optometrist);
        $this->validateClinicHours($scheduledAt, $durationMinutes);
        $this->validateAvailability($scheduledAt, $durationMinutes, $optometrist, $ignoreAppointment, $enforceGrid);
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
            durationMinutes: $durationMinutes,
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
        bool $enforceGrid,
    ): void {
        $decision = $this->evaluateAppointmentAvailability->handle(
            startsAt: $scheduledAt,
            durationMinutes: $durationMinutes,
            optometrist: $optometrist,
            ignoreAppointment: $ignoreAppointment,
            enforceFuture: false,
            enforceGrid: $enforceGrid,
        );

        if (! $decision->available) {
            throw ValidationException::withMessages([
                'scheduled_at' => [$this->messageForReason($decision->reason)],
            ]);
        }
    }

    private function messageForReason(?string $reason): string
    {
        return match ($reason) {
            'outside_slot_grid' => 'Please choose one of the available appointment times.',
            default => 'This time slot is not available. Please choose another time.',
        };
    }
}
