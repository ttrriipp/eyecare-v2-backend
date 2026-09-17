<?php

namespace App\Actions\Appointments;

use App\Models\Appointment;
use App\Models\ScheduleOverride;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ListAvailableAppointmentSlots
{
    public function __construct(private readonly EvaluateAppointmentAvailability $evaluateAppointmentAvailability) {}

    /**
     * @return array<int, AppointmentAvailabilityDecision>
     */
    public function handle(
        CarbonInterface $date,
        int $durationMinutes,
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

        // Load day-scoped data once to avoid per-slot queries
        $blockingAppointments = $this->evaluateAppointmentAvailability->blockingAppointmentsBetween(
            startsAt: $slot,
            endsAt: $closingTime,
            ignoreAppointment: $ignoreAppointment,
        );

        $optometrists = User::query()->optometrists()->get();

        $dateString = $slot->toDateString();
        $absences = ScheduleOverride::query()
            ->where('override_date', $dateString)
            ->where('type', ScheduleOverride::TYPE_PROVIDER_ABSENCE)
            ->whereNotNull('user_id')
            ->get()
            ->keyBy('user_id');

        while ($slot->copy()->addMinutes($durationMinutes)->lte($closingTime)) {
            $slotEnd = $slot->copy()->addMinutes($durationMinutes);

            // Compute provider presence for this exact interval using pre-loaded data.
            $providerAvailable = $this->providerAvailableForInterval($optometrists, $absences, $slot, $slotEnd);

            $slots[] = $this->evaluateAppointmentAvailability->handle(
                startsAt: $slot,
                durationMinutes: $durationMinutes,
                optometrist: $optometrist,
                ignoreAppointment: $ignoreAppointment,
                enforceFuture: true,
                blockingAppointments: $blockingAppointments,
                providerAvailable: $providerAvailable,
                schedule: $schedule,
            );

            $slot->addMinutes($intervalMinutes);
        }

        return $slots;
    }

    /**
     * Determine whether a provider is available for an exact interval using pre-loaded data.
     *
     * @param  Collection<int, User>  $optometrists
     * @param  Collection<int, ScheduleOverride>  $absences
     */
    private function providerAvailableForInterval(
        Collection $optometrists,
        Collection $absences,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): bool {

        foreach ($optometrists as $optometrist) {
            $absence = $absences->get($optometrist->id);

            if ($absence !== null) {
                if ($absence->start_time === null && $absence->end_time === null) {
                    continue;
                }

                if ($absence->start_time !== null && $absence->end_time !== null) {
                    $absenceStart = $startsAt->copy()->startOfDay()->setTimeFromTimeString(
                        $absence->start_time instanceof \DateTimeInterface
                            ? $absence->start_time->format('H:i')
                            : (string) $absence->start_time,
                    );
                    $absenceEnd = $startsAt->copy()->startOfDay()->setTimeFromTimeString(
                        $absence->end_time instanceof \DateTimeInterface
                            ? $absence->end_time->format('H:i')
                            : (string) $absence->end_time,
                    );

                    if ($startsAt->lt($absenceEnd) && $endsAt->gt($absenceStart)) {
                        continue;
                    }
                }
            }

            return true;
        }

        return false;
    }
}
