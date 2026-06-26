<?php

namespace App\Filament\Resources\Appointments\Widgets;

use App\Models\Appointment;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

class AppointmentStatsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public ?string $activeTab = null;

    #[On('appointment-tab-changed')]
    public function updateTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    protected function getStats(): array
    {
        $query = Appointment::query();

        if ($this->activeTab && $this->activeTab !== 'all') {
            $query->whereHas('status', fn ($q) => $q->where('name', $this->activeTab));
        }

        $label = $this->activeTab && $this->activeTab !== 'all'
            ? ucwords(str_replace('_', ' ', $this->activeTab)).' appointments'
            : 'Total appointments';

        $upcomingCount = Appointment::query()
            ->where('scheduled_at', '>=', now())
            ->whereHas('status', fn ($q) => $q->whereNotIn('name', ['completed', 'cancelled']))
            ->count();

        $todayCount = Appointment::query()
            ->whereDate('scheduled_at', today())
            ->count();

        return [
            Stat::make($label, number_format($query->count())),
            Stat::make('Today', number_format($todayCount)),
            Stat::make('Upcoming', number_format($upcomingCount)),
        ];
    }
}
