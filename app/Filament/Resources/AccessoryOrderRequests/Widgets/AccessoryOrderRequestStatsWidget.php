<?php

namespace App\Filament\Resources\AccessoryOrderRequests\Widgets;

use App\Enums\AccessoryOrderRequestStatus;
use App\Models\AccessoryOrderRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class AccessoryOrderRequestStatsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $pendingCount = AccessoryOrderRequest::query()
            ->where('status', AccessoryOrderRequestStatus::Pending)
            ->count();

        $acceptedCount = AccessoryOrderRequest::query()
            ->where('status', AccessoryOrderRequestStatus::Accepted)
            ->count();

        $rejectedCount = AccessoryOrderRequest::query()
            ->where('status', AccessoryOrderRequestStatus::Rejected)
            ->count();

        $cancelledCount = AccessoryOrderRequest::query()
            ->where('status', AccessoryOrderRequestStatus::Cancelled)
            ->count();

        return [
            Stat::make('Pending Review', Number::format($pendingCount))
                ->color($pendingCount > 0 ? 'warning' : 'gray'),
            Stat::make('Accepted', Number::format($acceptedCount))
                ->color($acceptedCount > 0 ? 'success' : 'gray'),
            Stat::make('Rejected', Number::format($rejectedCount))
                ->color($rejectedCount > 0 ? 'danger' : 'gray'),
            Stat::make('Cancelled', Number::format($cancelledCount))
                ->color('gray'),
        ];
    }
}
