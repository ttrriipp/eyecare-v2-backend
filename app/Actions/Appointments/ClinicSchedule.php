<?php

namespace App\Actions\Appointments;

use App\Enums\ScheduleOverrideType;
use App\Models\ClinicHour;
use App\Models\ScheduleOverride;
use Carbon\CarbonInterface;

/**
 * Resolved clinic schedule for a specific date.
 */
readonly class ClinicSchedule
{
    /**
     * @param  list<string>  $overrideTypes
     */
    public function __construct(
        public string $openTime,
        public string $closeTime,
        public int $slotIntervalMinutes,
        public bool $isClosed,
        public ?string $earlyCloseTime = null,
        public array $overrideTypes = [],
    ) {}

    public static function forDate(CarbonInterface $date): self
    {
        $weekday = $date->dayOfWeek;

        $clinicHour = ClinicHour::query()
            ->where('weekday', $weekday)
            ->first();

        $overrides = ScheduleOverride::query()
            ->where('override_date', $date->toDateString())
            ->get();

        $isClosed = $clinicHour === null
            ? $weekday === 0
            : ! $clinicHour->enabled;
        $overrideTypes = [];

        foreach ($overrides as $override) {
            $overrideTypes[] = $override->type->value;

            if ($override->type === ScheduleOverrideType::Closed) {
                $isClosed = true;
            }
        }

        $openTime = $clinicHour?->open_time?->format('H:i')
            ?? config('appointments.clinic_hours.opens_at', '09:00');

        $closeTime = $clinicHour?->close_time?->format('H:i')
            ?? config('appointments.clinic_hours.closes_at', '17:00');

        $earlyCloseTime = null;
        foreach ($overrides as $override) {
            if ($override->type === ScheduleOverrideType::EarlyClose && $override->start_time !== null) {
                $earlyCloseTime = $override->start_time->format('H:i');
                $closeTime = $earlyCloseTime;
            }
        }

        $slotIntervalMinutes = (int) config('appointments.clinic_hours.slot_interval_minutes', 15);

        return new self(
            openTime: $openTime,
            closeTime: $closeTime,
            slotIntervalMinutes: $slotIntervalMinutes,
            isClosed: $isClosed,
            earlyCloseTime: $earlyCloseTime,
            overrideTypes: $overrideTypes,
        );
    }
}
