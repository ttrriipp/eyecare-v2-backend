<?php

namespace App\Filament\Resources\Billings\Widgets;

use App\Models\Billing;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BillingStatsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $total = Billing::query()->count();

        $unpaidBalance = Billing::query()
            ->whereHas('status', fn ($q) => $q->whereIn('name', ['issued', 'partially_paid']))
            ->sum('balance_due');

        $collected = Billing::query()
            ->whereHas('status', fn ($q) => $q->where('name', 'paid'))
            ->sum('total_amount');

        return [
            Stat::make('Total Billings', number_format($total)),
            Stat::make('Unpaid Balance', '₱'.number_format((float) $unpaidBalance, 2))
                ->color('warning'),
            Stat::make('Collected', '₱'.number_format((float) $collected, 2))
                ->color('success'),
        ];
    }
}
