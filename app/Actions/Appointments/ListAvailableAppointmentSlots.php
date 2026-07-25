<?php

namespace App\Actions\Appointments;

use App\Models\Appointment;
use App\Models\User;
use App\Models\VisitReason;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class ListAvailableAppointmentSlots
{
    public function __construct(private readonly EvaluateAppointmentAvailability $evaluateAppointmentAvailability) {}

    /**
     * @return array<int, AppointmentAvailabilityDecision>
     */
    public function handle(
        CarbonInterface $date,
        VisitReason $visitReason,
        ?User $optometrist = null,
        ?Appointment $ignoreAppointment = null,
    ): array {
        $schedule = ClinicSchedule::forDate($date);

        if ($schedule->isClosed) {
            return [];
        }

        $slot = Carbon::parse(
            $date->format('Y-m-d').' '.$schedule->openTime,
            config('app.timezone'),
        );
        $closingTime = Carbon::parse(
            $date->format('Y-m-d').' '.$schedule->closeTime,
            config('app.timezone'),
        );
        $intervalMinutes = $schedule->slotIntervalMinutes;
        $slots = [];

        $blockingAppointments = $this->evaluateAppointmentAvailability->blockingAppointmentsBetween(
            startsAt: $slot,
            endsAt: $closingTime,
            ignoreAppointment: $ignoreAppointment,
        );
        $capacity = $this->evaluateAppointmentAvailability->eligibleOptometristCapacity();

        while ($slot->copy()->addMinutes($visitReason->duration_minutes)->lte($closingTime)) {
            $slots[] = $this->evaluateAppointmentAvailability->handle(
                startsAt: $slot,
                visitReason: $visitReason,
                optometrist: $optometrist,
                ignoreAppointment: $ignoreAppointment,
                enforceFuture: true,
                blockingAppointments: $blockingAppointments,
                capacity: $capacity,
                schedule: $schedule,
            );

            $slot->addMinutes($intervalMinutes);
        }

        return $slots;
    }
}
