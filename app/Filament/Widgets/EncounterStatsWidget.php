<?php

namespace App\Filament\Widgets;

use App\Enums\EncounterStatus;
use App\Models\Encounter;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

class EncounterStatsWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public ?string $activeTab = null;

    #[On('encounter-tab-changed')]
    public function updateTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    protected function getStats(): array
    {
        $query = Encounter::query();

        if ($this->activeTab && $this->activeTab !== 'all') {
            $query->where('status', $this->activeTab);
        }

        $label = $this->activeTab && $this->activeTab !== 'all'
            ? match ($this->activeTab) {
                'planned' => 'Planned encounters',
                'in_progress' => 'In Progress encounters',
                'completed' => 'Completed encounters',
                'cancelled' => 'Cancelled encounters',
                default => 'Encounters',
            }
        : 'Total encounters';

        $todayCount = Encounter::query()
            ->whereDate('created_at', today())
            ->count();

        $inProgressCount = Encounter::query()
            ->where('status', EncounterStatus::InProgress)
            ->count();

        return [
            Stat::make($label, number_format($query->count())),
            Stat::make('Today', number_format($todayCount)),
            Stat::make('In Progress', number_format($inProgressCount)),
        ];
    }
}
