<?php

namespace App\Filament\Pages\Reports;

use App\Models\Billing;
use App\Models\BillingStatus;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SalesReport extends BaseReport
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Sales Report';

    /**
     * @return array<int, Stat>
     */
    public function getStats(): array
    {
        $query = Billing::query()
            ->when($this->dateFrom, fn ($q) => $q->whereDate('issued_at', '>=', $this->dateFrom))
            ->when($this->dateUntil, fn ($q) => $q->whereDate('issued_at', '<=', $this->dateUntil));

        $total = (clone $query)->count();
        $billed = (float) (clone $query)->sum('total_amount');
        $paid = (float) (clone $query)->sum('amount_paid');
        $outstanding = (float) (clone $query)->sum('balance_due');

        return [
            Stat::make('Total billings', number_format($total)),
            Stat::make('Total billed', '₱'.number_format($billed, 2)),
            Stat::make('Total paid', '₱'.number_format($paid, 2))->color('success'),
            Stat::make('Outstanding', '₱'.number_format($outstanding, 2))->color($outstanding > 0 ? 'warning' : 'success'),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function getBreakdown(): array
    {
        $query = Billing::query()
            ->when($this->dateFrom, fn ($q) => $q->whereDate('issued_at', '>=', $this->dateFrom))
            ->when($this->dateUntil, fn ($q) => $q->whereDate('issued_at', '<=', $this->dateUntil));

        $statuses = BillingStatus::query()->pluck('name', 'id');
        $counts = (clone $query)->selectRaw('billing_status_id, count(*) as total')
            ->groupBy('billing_status_id')
            ->pluck('total', 'billing_status_id');

        $breakdown = [];
        foreach ($statuses as $id => $name) {
            $breakdown[ucfirst($name)] = (int) ($counts[$id] ?? 0);
        }

        return $breakdown;
    }
}
