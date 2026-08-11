<?php

namespace App\Filament\Resources\OpticalOrders\Widgets;

use App\Enums\JobOrderStatus;
use App\Models\JobOrder;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OpticalOrderStatsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $confirmedCount = JobOrder::query()
            ->where('status', JobOrderStatus::Queued)
            ->count();

        $processingCount = JobOrder::query()
            ->where('status', JobOrderStatus::InProgress)
            ->count();

        $readyCount = JobOrder::query()
            ->where('status', JobOrderStatus::ReadyForDispensing)
            ->count();

        $completedCount = JobOrder::query()
            ->where('status', JobOrderStatus::Dispensed)
            ->count();

        return [
            Stat::make('Confirmed', number_format($confirmedCount))
                ->color('warning'),
            Stat::make('Processing', number_format($processingCount))
                ->color('primary'),
            Stat::make('Ready for Pickup', number_format($readyCount))
                ->color('success'),
            Stat::make('Completed', number_format($completedCount))
                ->color('gray'),
        ];
    }
}
