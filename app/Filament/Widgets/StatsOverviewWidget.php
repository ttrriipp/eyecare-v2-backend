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
use Illuminate\Support\Facades\Cache;

class StatsOverviewWidget extends BaseStatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        // Cache raw numeric data only — Stat objects are not safely serializable.
        $data = Cache::remember('dashboard.stats', now()->addMinutes(2), fn () => $this->computeStatsData());

        return [
            $this->todaysAppointmentsStat($data),
            $this->walkInQueueStat($data),
            $this->readyForPickupStat($data),
            $this->revenueStat($data),
            $this->pendingOrdersStat($data),
            $this->unpaidBillingsStat($data),
            $this->lowStockStat($data),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function computeStatsData(): array
    {
        $today = $this->confirmedAppointmentsOn(today());
        $yesterday = $this->confirmedAppointmentsOn(today()->subDay());
        $thisMonthRevenue = $this->revenueBetween(now()->startOfMonth(), now()->endOfMonth());
        $lastMonthRevenue = $this->revenueBetween(
            now()->subMonthNoOverflow()->startOfMonth(),
            now()->subMonthNoOverflow()->endOfMonth(),
        );
        $unpaidQuery = Billing::query()
            ->whereHas('status', fn ($q) => $q->whereIn('name', ['issued', 'partially_paid']));

        return [
            'today_confirmed' => $today,
            'yesterday_confirmed' => $yesterday,
            'today_confirmed_chart' => $this->dailyConfirmedAppointments(7),
            'walk_in_queue' => Appointment::query()
                ->whereHas('status', fn ($q) => $q->where('name', 'pending'))
                ->whereDate('scheduled_at', today())
                ->count(),
            'this_month_revenue' => $thisMonthRevenue,
            'last_month_revenue' => $lastMonthRevenue,
            'daily_revenue_chart' => $this->dailyRevenue(14),
            'pending_orders' => Order::query()
                ->whereHas('status', fn ($q) => $q->whereIn('name', ['requested', 'under_review']))
                ->count(),
            'daily_orders_chart' => $this->dailyNewOrders(7),
            'unpaid_count' => (clone $unpaidQuery)->count(),
            'unpaid_balance' => (float) (clone $unpaidQuery)->sum('balance_due'),
            'low_stock' => ProductVariant::query()
                ->where('is_active', true)
                ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
                ->count(),
            'ready_for_pickup' => Order::query()
                ->whereHas('status', fn ($q) => $q->where('name', 'ready_for_pickup'))
                ->count(),
        ];
    }

    /** @param array<string, mixed> $data */
    private function todaysAppointmentsStat(array $data): Stat
    {
        $today = $data['today_confirmed'];
        $delta = $today - $data['yesterday_confirmed'];

        $description = match (true) {
            $delta > 0 => "{$delta} more than yesterday",
            $delta < 0 => abs($delta).' fewer than yesterday',
            default => 'Same as yesterday',
        };

        return Stat::make("Today's appointments", number_format($today))
            ->description($description)
            ->descriptionIcon($delta >= 0 ? Heroicon::ArrowUpRight : Heroicon::ArrowDownRight)
            ->descriptionColor($delta > 0 ? 'success' : ($delta < 0 ? 'danger' : 'gray'))
            ->chart($data['today_confirmed_chart'])
            ->color('info');
    }

    /** @param array<string, mixed> $data */
    private function walkInQueueStat(array $data): Stat
    {
        $waiting = $data['walk_in_queue'];

        return Stat::make('Waiting today', number_format($waiting))
            ->description($waiting > 0 ? 'Pending appointments for today' : 'No pending appointments')
            ->descriptionIcon($waiting > 0 ? Heroicon::UserGroup : Heroicon::CheckCircle)
            ->descriptionColor($waiting > 0 ? 'warning' : 'success')
            ->color($waiting > 0 ? 'warning' : 'success')
            ->url('/admin/appointments?tableFilters[status][value]=pending');
    }

    /** @param array<string, mixed> $data */
    private function readyForPickupStat(array $data): Stat
    {
        $count = $data['ready_for_pickup'];

        return Stat::make('Ready for pickup', number_format($count))
            ->description($count > 0 ? 'Orders awaiting patient collection' : 'No orders waiting')
            ->descriptionIcon($count > 0 ? Heroicon::ShoppingBag : Heroicon::CheckCircle)
            ->descriptionColor($count > 0 ? 'info' : 'success')
            ->color($count > 0 ? 'info' : 'success')
            ->url('/admin/orders?activeTab=ready_for_pickup');
    }

    /** @param array<string, mixed> $data */
    private function revenueStat(array $data): Stat
    {
        [$description, $icon, $color] = $this->revenueDelta(
            $data['this_month_revenue'],
            $data['last_month_revenue'],
        );

        return Stat::make('Revenue this month', '₱'.number_format($data['this_month_revenue'], 2))
            ->description($description)
            ->descriptionIcon($icon)
            ->descriptionColor($color)
            ->chart($data['daily_revenue_chart'])
            ->color('success');
    }

    /** @param array<string, mixed> $data */
    private function pendingOrdersStat(array $data): Stat
    {
        $pending = $data['pending_orders'];

        return Stat::make('Pending orders', number_format($pending))
            ->description($pending > 0 ? 'Awaiting confirmation' : 'All caught up')
            ->descriptionIcon($pending > 0 ? Heroicon::Clock : Heroicon::CheckCircle)
            ->chart($data['daily_orders_chart'])
            ->color($pending > 0 ? 'warning' : 'success');
    }

    /** @param array<string, mixed> $data */
    private function unpaidBillingsStat(array $data): Stat
    {
        $count = $data['unpaid_count'];
        $outstanding = $data['unpaid_balance'];

        return Stat::make('Unpaid billings', number_format($count))
            ->description('₱'.number_format($outstanding, 2).' outstanding')
            ->descriptionIcon(Heroicon::Banknotes)
            ->color($count > 0 ? 'warning' : 'success');
    }

    /** @param array<string, mixed> $data */
    private function lowStockStat(array $data): Stat
    {
        $lowStock = $data['low_stock'];

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
