<?php

namespace App\Filament\Pages\Reports;

use Filament\Pages\Page;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\Url;
use UnitEnum;

abstract class BaseReport extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected string $view = 'filament.pages.reports.report';

    #[Url]
    public ?string $dateFrom = null;

    #[Url]
    public ?string $dateUntil = null;

    public ?string $activePreset = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        if ($this->dateFrom === null && $this->dateUntil === null) {
            $this->applyPreset('this_month');
        }
    }

    /**
     * Quick-select date range presets shown as pills.
     *
     * @return array<string, string>
     */
    public function getPresets(): array
    {
        return [
            'this_month' => 'This month',
            'last_month' => 'Last month',
            'last_30' => 'Last 30 days',
            'this_year' => 'This year',
            'all_time' => 'All time',
        ];
    }

    public function applyPreset(string $preset): void
    {
        [$from, $until] = match ($preset) {
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'last_30' => [now()->subDays(29), now()],
            'this_year' => [now()->startOfYear(), now()],
            'all_time' => [null, null],
            default => [now()->startOfMonth(), now()],
        };

        $this->dateFrom = $from?->toDateString();
        $this->dateUntil = $until?->toDateString();
        $this->activePreset = $preset;
    }

    public function updatedDateFrom(): void
    {
        $this->activePreset = null;
    }

    public function updatedDateUntil(): void
    {
        $this->activePreset = null;
    }

    /**
     * KPI stat cards for the report.
     *
     * @return array<int, Stat>
     */
    abstract public function getStats(): array;

    /**
     * Status/category breakdown as label => count.
     *
     * @return array<string, int>
     */
    abstract public function getBreakdown(): array;
}
