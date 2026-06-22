<?php

namespace App\Filament\Resources\Orders\Widgets;

use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrderStatsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    public ?string $activeTab = null;

    protected function getStats(): array
    {
        $query = Order::query();

        if ($this->activeTab && $this->activeTab !== 'all') {
            $query->whereHas('status', fn ($q) => $q->where('name', $this->activeTab));
        }

        $total = $query->count();
        $totalAmount = $query->sum('total_amount');
        $avg = $total > 0 ? $totalAmount / $total : 0;

        $label = $this->activeTab && $this->activeTab !== 'all'
            ? ucwords(str_replace('_', ' ', $this->activeTab)).' orders'
            : 'Total orders';

        $openCount = Order::query()
            ->whereHas('status', fn ($q) => $q->whereNotIn('name', ['completed', 'cancelled']))
            ->count();

        return [
            Stat::make($label, number_format($total)),
            Stat::make('Open orders', number_format($openCount)),
            Stat::make('Average price', '₱'.number_format((float) $avg, 2)),
        ];
    }
}
