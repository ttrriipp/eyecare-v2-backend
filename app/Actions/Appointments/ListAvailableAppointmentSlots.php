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
        $slot = Carbon::parse(
            $date->format('Y-m-d').' '.config('appointments.clinic_hours.opens_at', '09:00'),
            config('app.timezone'),
        );
        $closingTime = Carbon::parse(
            $date->format('Y-m-d').' '.config('appointments.clinic_hours.closes_at', '17:00'),
            config('app.timezone'),
        );
        $intervalMinutes = config('appointments.clinic_hours.slot_interval_minutes', 15);
        $closedWeekdays = config('appointments.clinic_hours.closed_weekdays', [0]);
        $slots = [];

        if (in_array($slot->dayOfWeek, $closedWeekdays, true)) {
            return [];
        }

        while ($slot->copy()->addMinutes($visitReason->duration_minutes)->lte($closingTime)) {
            $slots[] = $this->evaluateAppointmentAvailability->handle(
                startsAt: $slot,
                visitReason: $visitReason,
                optometrist: $optometrist,
                ignoreAppointment: $ignoreAppointment,
                enforceFuture: true,
            );

            $slot->addMinutes($intervalMinutes);
        }

        return $slots;
    }
}
