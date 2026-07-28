<?php

namespace App\Services;

use App\Models\ClinicHour;

class ClinicHoursFormatter
{
    /**
     * Format enabled clinic hours into a concise weekly string.
     * Groups consecutive days with the same hours.
     *
     * Example: "Mon–Fri: 9:00 AM – 5:00 PM, Sat: 9:00 AM – 1:00 PM"
     */
    public function formatWeeklyHours(): string
    {
        $hours = ClinicHour::query()
            ->where('enabled', true)
            ->orderBy('weekday')
            ->get()
            ->keyBy('weekday');

        if ($hours->isEmpty()) {
            return 'Hours not available';
        }

        $dayNames = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];
        $groups = [];
        $currentGroup = null;

        for ($day = 0; $day <= 6; $day++) {
            $hour = $hours->get($day);

            if ($hour === null) {
                if ($currentGroup !== null) {
                    $groups[] = $currentGroup;
                    $currentGroup = null;
                }

                continue;
            }

            $timeRange = $hour->open_time->format('g:i A').' – '.$hour->close_time->format('g:i A');

            if ($currentGroup !== null && $currentGroup['time'] === $timeRange) {
                $currentGroup['endDay'] = $day;
            } else {
                if ($currentGroup !== null) {
                    $groups[] = $currentGroup;
                }
                $currentGroup = [
                    'startDay' => $day,
                    'endDay' => $day,
                    'time' => $timeRange,
                ];
            }
        }

        if ($currentGroup !== null) {
            $groups[] = $currentGroup;
        }

        return collect($groups)->map(function (array $group) use ($dayNames): string {
            $dayLabel = $group['startDay'] === $group['endDay']
                ? $dayNames[$group['startDay']]
                : $dayNames[$group['startDay']].'–'.$dayNames[$group['endDay']];

            return "{$dayLabel}: {$group['time']}";
        })->implode(', ');
    }
}
