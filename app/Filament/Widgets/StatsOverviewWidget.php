<?php

namespace App\Filament\Widgets;

use App\Models\Appointment;
use App\Models\Billing;
use App\Models\Order;
use App\Models\ProductVariant;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseStatsOverviewWidget
{
    protected function getStats(): array
    {
        $todayAppointments = Appointment::query()
            ->whereHas('status', fn ($q) => $q->where('name', 'confirmed'))
            ->whereDate('scheduled_at', today())
            ->count();

        $pendingOrders = Order::query()
            ->whereHas('status', fn ($q) => $q->whereIn('name', ['requested', 'under_review']))
            ->count();

        $lowStockVariants = ProductVariant::query()
            ->where('is_active', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->count();

        $unpaidBillings = Billing::query()
            ->whereHas('status', fn ($q) => $q->whereIn('name', ['draft', 'issued', 'partially_paid']))
            ->count();

        return [
            Stat::make("Today's Appointments", $todayAppointments)
                ->color('info'),
            Stat::make('Pending Orders', $pendingOrders)
                ->color($pendingOrders > 0 ? 'warning' : 'success'),
            Stat::make('Low Stock Variants', $lowStockVariants)
                ->color($lowStockVariants > 0 ? 'danger' : 'success'),
            Stat::make('Unpaid Billings', $unpaidBillings)
                ->color($unpaidBillings > 0 ? 'warning' : 'success'),
        ];
    }
}
