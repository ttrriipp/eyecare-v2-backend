<?php

namespace App\Filament\Widgets;

use App\Models\Appointment;
use App\Models\Billing;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StatsOverviewWidget extends BaseStatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        return [
            $this->todaysAppointmentsStat(),
            $this->revenueStat(),
            $this->pendingOrdersStat(),
            $this->unpaidBillingsStat(),
            $this->lowStockStat(),
        ];
    }

    private function todaysAppointmentsStat(): Stat
    {
        $today = $this->confirmedAppointmentsOn(today());
        $yesterday = $this->confirmedAppointmentsOn(today()->subDay());
        $delta = $today - $yesterday;

        $description = match (true) {
            $delta > 0 => "{$delta} more than yesterday",
            $delta < 0 => abs($delta).' fewer than yesterday',
            default => 'Same as yesterday',
        };

        return Stat::make("Today's appointments", number_format($today))
            ->description($description)
            ->descriptionIcon($delta >= 0 ? Heroicon::ArrowUpRight : Heroicon::ArrowDownRight)
            ->descriptionColor($delta > 0 ? 'success' : ($delta < 0 ? 'danger' : 'gray'))
            ->chart($this->dailyConfirmedAppointments(7))
            ->color('info');
    }

    private function revenueStat(): Stat
    {
        $thisMonth = $this->revenueBetween(now()->startOfMonth(), now()->endOfMonth());
        $lastMonth = $this->revenueBetween(
            now()->subMonthNoOverflow()->startOfMonth(),
            now()->subMonthNoOverflow()->endOfMonth(),
        );

        [$description, $icon, $color] = $this->revenueDelta($thisMonth, $lastMonth);

        return Stat::make('Revenue this month', '₱'.number_format($thisMonth, 2))
            ->description($description)
            ->descriptionIcon($icon)
            ->descriptionColor($color)
            ->chart($this->dailyRevenue(14))
            ->color('success');
    }

    private function pendingOrdersStat(): Stat
    {
        $pending = Order::query()
            ->whereHas('status', fn ($query) => $query->whereIn('name', ['requested', 'under_review']))
            ->count();

        return Stat::make('Pending orders', number_format($pending))
            ->description($pending > 0 ? 'Awaiting confirmation' : 'All caught up')
            ->descriptionIcon($pending > 0 ? Heroicon::Clock : Heroicon::CheckCircle)
            ->chart($this->dailyNewOrders(7))
            ->color($pending > 0 ? 'warning' : 'success');
    }

    private function unpaidBillingsStat(): Stat
    {
        $unpaid = Billing::query()
            ->whereHas('status', fn ($query) => $query->whereIn('name', ['issued', 'partially_paid']));

        $count = (clone $unpaid)->count();
        $outstanding = (float) (clone $unpaid)->sum('balance_due');

        return Stat::make('Unpaid billings', number_format($count))
            ->description('₱'.number_format($outstanding, 2).' outstanding')
            ->descriptionIcon(Heroicon::Banknotes)
            ->color($count > 0 ? 'warning' : 'success');
    }

    private function lowStockStat(): Stat
    {
        $lowStock = ProductVariant::query()
            ->where('is_active', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->count();

        return Stat::make('Low stock variants', number_format($lowStock))
            ->description($lowStock > 0 ? 'Reorder soon' : 'Stock healthy')
            ->descriptionIcon($lowStock > 0 ? Heroicon::ExclamationTriangle : Heroicon::CheckCircle)
            ->color($lowStock > 0 ? 'danger' : 'success');
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function confirmedAppointmentsOn(Carbon $date): int
    {
        return Appointment::query()
            ->whereHas('status', fn ($query) => $query->where('name', 'confirmed'))
            ->whereDate('scheduled_at', $date)
            ->count();
    }

    /**
     * @return list<int>
     */
    private function dailyConfirmedAppointments(int $days): array
    {
        $start = today()->subDays($days - 1);

        $byDay = Appointment::query()
            ->whereHas('status', fn ($query) => $query->where('name', 'confirmed'))
            ->whereDate('scheduled_at', '>=', $start)
            ->whereDate('scheduled_at', '<=', today())
            ->get(['scheduled_at'])
            ->groupBy(fn (Appointment $appointment): string => $appointment->scheduled_at->toDateString())
            ->map
            ->count();

        return $this->fillDailySeries($byDay, $start, $days);
    }

    /**
     * @return list<int>
     */
    private function dailyNewOrders(int $days): array
    {
        $start = today()->subDays($days - 1);

        $byDay = Order::query()
            ->whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', today())
            ->get(['created_at'])
            ->groupBy(fn (Order $order): string => $order->created_at->toDateString())
            ->map
            ->count();

        return $this->fillDailySeries($byDay, $start, $days);
    }

    /**
     * @return list<float>
     */
    private function dailyRevenue(int $days): array
    {
        $start = today()->subDays($days - 1);

        $byDay = Payment::query()
            ->whereHas('status', fn ($query) => $query->where('name', 'posted'))
            ->whereDate('paid_at', '>=', $start)
            ->whereDate('paid_at', '<=', today())
            ->get(['amount', 'paid_at'])
            ->groupBy(fn (Payment $payment): string => $payment->paid_at->toDateString())
            ->map(fn (Collection $group): float => (float) $group->sum('amount'));

        $series = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $series[] = round((float) $byDay->get($start->copy()->addDays($offset)->toDateString(), 0.0), 2);
        }

        return $series;
    }

    private function revenueBetween(Carbon $from, Carbon $to): float
    {
        return (float) Payment::query()
            ->whereHas('status', fn ($query) => $query->where('name', 'posted'))
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount');
    }

    /**
     * @return array{0: string, 1: Heroicon, 2: string}
     */
    private function revenueDelta(float $thisMonth, float $lastMonth): array
    {
        if ($lastMonth <= 0.0) {
            return $thisMonth > 0.0
                ? ['First revenue this month', Heroicon::ArrowTrendingUp, 'success']
                : ['No revenue yet', Heroicon::ArrowTrendingUp, 'gray'];
        }

        $change = (($thisMonth - $lastMonth) / $lastMonth) * 100;
        $rounded = number_format(abs($change), 0);

        return $change >= 0
            ? ["{$rounded}% vs last month", Heroicon::ArrowTrendingUp, 'success']
            : ["{$rounded}% vs last month", Heroicon::ArrowTrendingDown, 'danger'];
    }

    /**
     * @param  Collection<string, int>  $byDay
     * @return list<int>
     */
    private function fillDailySeries(Collection $byDay, Carbon $start, int $days): array
    {
        $series = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $series[] = (int) $byDay->get($start->copy()->addDays($offset)->toDateString(), 0);
        }

        return $series;
    }
}
