<?php

namespace App\Actions\Appointments;

use App\Enums\ScheduleOverrideType;
use App\Models\ProviderHour;
use App\Models\ScheduleOverride;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Resolves the clinic's and every optometrist's actual status for a range of
 * dates, applying schedule overrides on top of the weekly recurring pattern.
 *
 * A day only counts as genuinely "open" when the clinic's hours allow it AND
 * at least one optometrist is actually available — this clinic is operated
 * exclusively by optometrists, so hours alone never make a day bookable.
 */
class ResolveDailyAvailabilitySummary
{
    /**
     * @return Collection<int, DailyAvailabilitySummary>
     */
    public function handle(CarbonInterface $startDate, int $days = 8): Collection
    {
        $dates = collect(range(0, $days - 1))
            ->map(fn (int $offset): CarbonInterface => $startDate->copy()->addDays($offset));

        $endDate = $dates->last();

        $optometrists = User::query()
            ->optometrists()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $optometristIds = $optometrists->pluck('id');

        $providerHours = ProviderHour::query()
            ->whereIn('user_id', $optometristIds)
            ->get()
            ->keyBy(fn (ProviderHour $hour): string => "{$hour->user_id}:{$hour->weekday}");

        $absences = ScheduleOverride::query()
            ->where('type', ScheduleOverrideType::ProviderAbsence->value)
            ->whereIn('user_id', $optometristIds)
            ->whereBetween('override_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get()
            ->keyBy(fn (ScheduleOverride $override): string => "{$override->user_id}:{$override->override_date->toDateString()}");

        return $dates->map(
            fn (CarbonInterface $date): DailyAvailabilitySummary => $this->resolveDay(
                $date,
                $optometrists,
                $providerHours,
                $absences,
            )
        )->values();
    }

    /**
     * @param  Collection<int, User>  $optometrists
     * @param  Collection<string, ProviderHour>  $providerHours
     * @param  Collection<string, ScheduleOverride>  $absences
     */
    private function resolveDay(
        CarbonInterface $date,
        Collection $optometrists,
        Collection $providerHours,
        Collection $absences,
    ): DailyAvailabilitySummary {
        $schedule = ClinicSchedule::forDate($date);

        if ($schedule->isClosed) {
            return DailyAvailabilitySummary::closed($date);
        }

        $weekday = $date->dayOfWeek;
        $dateString = $date->toDateString();

        $statuses = $optometrists->map(
            fn (User $optometrist): OptometristDayStatus => $this->resolveOptometristDay(
                $optometrist,
                $weekday,
                $dateString,
                $providerHours,
                $absences,
            )
        )->values()->all();

        $anyoneAvailable = collect($statuses)->contains(
            fn (OptometristDayStatus $status): bool => in_array($status->status, ['in', 'away_partial'], true)
        );

        return $anyoneAvailable
            ? DailyAvailabilitySummary::open($date, $schedule->openTime, $schedule->closeTime, $schedule->earlyCloseTime, $statuses)
            : DailyAvailabilitySummary::noOptometristAvailable($date, $schedule->openTime, $schedule->closeTime, $schedule->earlyCloseTime, $statuses);
    }

    /**
     * @param  Collection<string, ProviderHour>  $providerHours
     * @param  Collection<string, ScheduleOverride>  $absences
     */
    private function resolveOptometristDay(
        User $optometrist,
        int $weekday,
        string $dateString,
        Collection $providerHours,
        Collection $absences,
    ): OptometristDayStatus {
        $hours = $providerHours->get("{$optometrist->id}:{$weekday}");

        if ($hours === null || ! $hours->enabled) {
            return OptometristDayStatus::notScheduled($optometrist);
        }

        $absence = $absences->get("{$optometrist->id}:{$dateString}");

        if ($absence === null) {
            return OptometristDayStatus::in(
                $optometrist,
                $hours->start_time->format('H:i'),
                $hours->end_time->format('H:i'),
            );
        }

        if ($absence->start_time === null || $absence->end_time === null) {
            return OptometristDayStatus::awayFullDay($optometrist, $absence->reason);
        }

        return OptometristDayStatus::awayPartialDay(
            $optometrist,
            $absence->start_time->format('H:i'),
            $absence->end_time->format('H:i'),
            $absence->reason,
        );
    }
}
