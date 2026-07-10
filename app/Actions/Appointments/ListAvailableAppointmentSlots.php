<?php

namespace App\Actions\Appointments;

use App\Models\User;
use App\Models\VisitReason;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ListAvailableAppointmentSlots
{
    public function __construct(private readonly ScheduleAppointment $scheduleAppointment) {}

    /**
     * @return array<int, CarbonInterface>
     */
    public function handle(CarbonInterface $date, VisitReason $visitReason, ?User $optometrist = null): array
    {
        $slot = Carbon::parse(
            $date->format('Y-m-d').' '.config('appointments.clinic_hours.opens_at', '09:00'),
            config('app.timezone'),
        );
        $closingTime = Carbon::parse(
            $date->format('Y-m-d').' '.config('appointments.clinic_hours.closes_at', '17:00'),
            config('app.timezone'),
        );
        $intervalMinutes = config('appointments.clinic_hours.slot_interval_minutes', 15);
        $availableSlots = [];

        while ($slot->copy()->addMinutes($visitReason->duration_minutes)->lte($closingTime)) {
            try {
                $this->scheduleAppointment->handle($slot, $visitReason, $optometrist);
                $availableSlots[] = $slot->copy();
            } catch (ValidationException) {
                // Unavailable candidates are omitted from the response.
            }

            $slot->addMinutes($intervalMinutes);
        }

        return $availableSlots;
    }
}
