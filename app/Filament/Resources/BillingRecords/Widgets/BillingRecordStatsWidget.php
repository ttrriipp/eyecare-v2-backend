<?php

namespace App\Filament\Resources\BillingRecords\Widgets;

use App\Enums\BillingRecordStatus;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class BillingRecordStatsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $balancesDueCount = BillingRecord::query()
            ->whereIn('status', [BillingRecordStatus::Unpaid, BillingRecordStatus::PartiallyPaid])
            ->count();

        $overdueCount = BillingRecord::query()
            ->whereIn('status', [BillingRecordStatus::Unpaid, BillingRecordStatus::PartiallyPaid])
            ->where('balance_due', '>', 0)
            ->whereNotNull('payment_due_date')
            ->where('payment_due_date', '<', today())
            ->count();

        $paidCount = BillingRecord::query()
            ->where('status', BillingRecordStatus::Paid)
            ->count();

        $collected = BillingPayment::query()
            ->where('status', 'posted')
            ->whereHas('billingRecord', fn (Builder $query): Builder => $query
                ->where('status', '!=', BillingRecordStatus::Voided))
            ->sum('amount');

        return [
            Stat::make('Balances Due', Number::format($balancesDueCount))
                ->color($balancesDueCount > 0 ? 'warning' : 'gray')
                ->description('Unpaid or partially paid'),

            Stat::make('Overdue', Number::format($overdueCount))
                ->color($overdueCount > 0 ? 'danger' : 'gray')
                ->description('Past the payment due date'),

            Stat::make('Paid', Number::format($paidCount))
                ->color($paidCount > 0 ? 'success' : 'gray')
                ->description('Fully settled bills'),

            Stat::make('Collected', '₱'.number_format((float) $collected, 2))
                ->color($collected > 0 ? 'primary' : 'gray')
                ->description('Posted payments on active bills'),
        ];
    }
}
