<?php

namespace App\Filament\Resources\Orders\Widgets;

use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrderStatsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $total = Order::query()->count();
        $open = Order::query()
            ->whereHas('status', fn ($q) => $q->whereNotIn('name', ['completed', 'cancelled']))
            ->count();
        $avg = Order::query()->avg('total_amount');

        return [
            Stat::make('Orders', number_format($total)),
            Stat::make('Open orders', number_format($open)),
            Stat::make('Average price', '₱'.number_format((float) $avg, 2)),
        ];
    }
}
