<?php

namespace App\Actions\Appointments;

use App\Models\Appointment;
use Carbon\CarbonInterface;

class ListAppointmentRequestAvailabilitySlots
{
    public function __construct(
        private readonly ListAvailableAppointmentSlots $listAvailableSlots,
    ) {}

    /**
     * @return array<int, AppointmentAvailabilityDecision>
     */
    public function handle(
        CarbonInterface $date,
        int $durationMinutes,
        ?int $excludeAppointmentId = null,
    ): array {
        $schedule = ClinicSchedule::forDate($date);

        if ($schedule->isClosed) {
            return [];
        }

        $ignoreAppointment = null;

        if ($excludeAppointmentId !== null) {
            $ignoreAppointment = Appointment::query()->find($excludeAppointmentId);
        }

        return $this->listAvailableSlots->handle(
            date: $date,
            durationMinutes: $durationMinutes,
            ignoreAppointment: $ignoreAppointment,
        );
    }
}
