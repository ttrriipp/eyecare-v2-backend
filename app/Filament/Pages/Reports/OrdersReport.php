<?php

namespace App\Filament\Pages\Reports;

use App\Models\Order;
use App\Models\OrderStatus;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrdersReport extends BaseReport
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Orders Report';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isAdmin() || $user?->role?->name === 'staff';
    }

    /**
     * @return array<int, Stat>
     */
    public function getStats(): array
    {
        $query = Order::query()
            ->when($this->dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateUntil, fn ($q) => $q->whereDate('created_at', '<=', $this->dateUntil));

        $total = (clone $query)->count();
        $revenue = (float) (clone $query)
            ->whereHas('status', fn ($q) => $q->where('name', 'completed'))
            ->sum('total_amount');

        return [
            Stat::make('Total orders', number_format($total)),
            Stat::make('Completed revenue', '₱'.number_format($revenue, 2))->color('success'),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function getBreakdown(): array
    {
        $query = Order::query()
            ->when($this->dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateUntil, fn ($q) => $q->whereDate('created_at', '<=', $this->dateUntil));

        $statuses = OrderStatus::query()->pluck('name', 'id');
        $counts = (clone $query)->selectRaw('order_status_id, count(*) as total')
            ->groupBy('order_status_id')
            ->pluck('total', 'order_status_id');

        $breakdown = [];
        foreach ($statuses as $id => $name) {
            $breakdown[ucwords(str_replace('_', ' ', $name))] = (int) ($counts[$id] ?? 0);
        }

        return $breakdown;
    }
}
