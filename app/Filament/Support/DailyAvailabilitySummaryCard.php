<?php

namespace App\Filament\Support;

use App\Actions\Appointments\DailyAvailabilitySummary;
use App\Actions\Appointments\OptometristDayStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class DailyAvailabilitySummaryCard
{
    /**
     * @param  Collection<int, DailyAvailabilitySummary>  $days
     */
    public static function render(Collection $days): HtmlString
    {
        $rows = $days
            ->map(fn (DailyAvailabilitySummary $day, int $index): string => self::renderDay($day, $index === 0))
            ->implode('<div class="border-t border-gray-200 dark:border-white/10"></div>');

        return new HtmlString('<div class="rounded-lg border border-gray-200 dark:border-white/10">'.$rows.'</div>');
    }

    private static function renderDay(DailyAvailabilitySummary $day, bool $isToday): string
    {
        $dateLabel = ($isToday ? 'TODAY · ' : '').$day->date->format('D, M j');

        [$statusLabel, $statusColor] = match ($day->status) {
            'closed' => ['Closed', 'text-danger-700 dark:text-danger-400'],
            'no_optometrist' => ['No optometrist available', 'text-warning-700 dark:text-warning-400'],
            'open' => [self::formatHoursLine($day), 'text-gray-600 dark:text-gray-300'],
        };

        $optometristRows = $day->status === 'closed'
            ? ''
            : collect($day->optometristStatuses)
                ->map(fn (OptometristDayStatus $status): string => self::renderOptometristRow($status))
                ->implode('');

        return '<div class="p-3">'
            .'<div class="flex items-center justify-between gap-x-3">'
            .'<span class="text-sm font-semibold text-gray-900 dark:text-white">'.e($dateLabel).'</span>'
            .'<span class="text-sm '.$statusColor.'">'.e($statusLabel).'</span>'
            .'</div>'
            .($optometristRows !== '' ? '<div class="mt-2 space-y-1">'.$optometristRows.'</div>' : '')
            .'</div>';
    }

    private static function formatHoursLine(DailyAvailabilitySummary $day): string
    {
        if ($day->earlyCloseTime !== null) {
            return 'Closes early at '.Carbon::parse($day->earlyCloseTime)->format('g:i A');
        }

        $open = Carbon::parse($day->openTime)->format('g:i A');
        $close = Carbon::parse($day->closeTime)->format('g:i A');

        return "Open {$open}\u{2013}{$close}";
    }

    private static function renderOptometristRow(OptometristDayStatus $status): string
    {
        $name = e($status->optometrist->full_name);

        [$detail, $color] = match ($status->status) {
            'in' => [self::formatRange($status->startTime, $status->endTime), 'text-success-700 dark:text-success-400'],
            'away_full' => ['away all day'.self::formatReason($status->reason), 'text-gray-500 dark:text-gray-400'],
            'away_partial' => ['away '.self::formatRange($status->startTime, $status->endTime).self::formatReason($status->reason), 'text-warning-700 dark:text-warning-400'],
            'not_scheduled' => ['not scheduled today', 'text-gray-400 dark:text-gray-500'],
        };

        return '<div class="flex items-center gap-x-2 text-xs">'
            .'<span class="font-medium text-gray-700 dark:text-gray-200">'.$name.'</span>'
            .'<span class="'.$color.'">'.e($detail).'</span>'
            .'</div>';
    }

    private static function formatRange(?string $start, ?string $end): string
    {
        if ($start === null || $end === null) {
            return '—';
        }

        return Carbon::parse($start)->format('g:i A')."\u{2013}".Carbon::parse($end)->format('g:i A');
    }

    private static function formatReason(?string $reason): string
    {
        return filled($reason) ? " · {$reason}" : '';
    }
}
