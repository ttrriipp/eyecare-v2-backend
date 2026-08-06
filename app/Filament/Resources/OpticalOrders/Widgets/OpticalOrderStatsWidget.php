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
        $totalOrders = JobOrder::query()->count();

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
            Stat::make('Total Orders', number_format($totalOrders)),
            Stat::make('Confirmed', number_format($confirmedCount)),
            Stat::make('Processing', number_format($processingCount)),
            Stat::make('Ready for Pickup', number_format($readyCount)),
            Stat::make('Completed', number_format($completedCount)),
        ];
    }
}
