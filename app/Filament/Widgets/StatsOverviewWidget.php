<?php

namespace App\Filament\Widgets;

use App\Enums\EncounterStatus;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\JobOrder;
use App\Models\ProductVariant;
use App\Models\Quotation;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class StatsOverviewWidget extends BaseStatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $data = Cache::remember('dashboard.stats', now()->addMinutes(2), fn () => $this->computeStatsData());

        return [
            Stat::make('Today\'s Appointments', $data['today_appointments'])
                ->description("{$data['yesterday_appointments']} yesterday")
                ->descriptionIcon(Heroicon::OutlinedCalendarDays)
                ->color('primary'),

            Stat::make('Waiting Today', $data['waiting_today'])
                ->description('Walk-in + arrived queue')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color('warning'),

            Stat::make('Active Encounters', $data['active_encounters'])
                ->description('In progress')
                ->descriptionIcon(Heroicon::OutlinedDocumentText)
                ->color('info'),

            Stat::make('Quotations Pending', $data['quotations_pending'])
                ->description('Awaiting decision')
                ->descriptionIcon(Heroicon::OutlinedDocumentCurrencyDollar)
                ->color('gray'),

            Stat::make('Ready for Dispensing', $data['ready_for_dispensing'])
                ->description('Job orders ready')
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success'),

            Stat::make('Low Stock Variants', $data['low_stock'])
                ->description('Below threshold')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function computeStatsData(): array
    {
        return [
            'today_appointments' => Appointment::query()
                ->whereDate('scheduled_at', today())
                ->whereHas('status', fn ($q) => $q->whereNotIn('name', ['cancelled', 'no_show']))
                ->count(),
            'yesterday_appointments' => Appointment::query()
                ->whereDate('scheduled_at', today()->subDay())
                ->whereHas('status', fn ($q) => $q->whereNotIn('name', ['cancelled', 'no_show']))
                ->count(),
            'waiting_today' => Appointment::query()
                ->whereDate('scheduled_at', today())
                ->whereHas('status', fn ($q) => $q->whereIn('name', ['pending', 'arrived']))
                ->count(),
            'active_encounters' => Encounter::query()
                ->where('status', EncounterStatus::InProgress)
                ->count(),
            'quotations_pending' => Quotation::query()
                ->where('status', QuotationStatus::Presented)
                ->count(),
            'ready_for_dispensing' => JobOrder::query()
                ->where('status', JobOrderStatus::ReadyForDispensing)
                ->count(),
            'low_stock' => ProductVariant::query()
                ->where('is_active', true)
                ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
                ->where('low_stock_threshold', '>', 0)
                ->count(),
        ];
    }
}
