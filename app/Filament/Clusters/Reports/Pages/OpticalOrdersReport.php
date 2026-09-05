<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Enums\JobOrderStatus;
use App\Models\DispensingEvent;
use App\Models\JobOrder;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OpticalOrdersReport extends ReportsClusterPage
{
    protected static ?string $title = 'Optical Orders';

    protected static ?string $navigationLabel = 'Optical Orders';

    protected static ?int $navigationSort = 3;

    /**
     * @return array<string, string>
     */
    public function getMetricDefinitions(): array
    {
        return [
            'Orders created' => 'Orders created in the selected period.',
            'Dispensed' => 'Dispensing events recorded in the selected period.',
            'Cancelled' => 'Current cancelled orders whose cancelled_at falls in the selected period.',
            'Avg. time to dispense' => 'Average time from order creation to dispensing event for events in the period.',
            'Supplier mode' => 'Created-cohort orders grouped by in-house or external supplier fulfillment.',
        ];
    }

    /**
     * @return array{stats: array<int, Stat>, sections: array<int, array<string, mixed>>}
     */
    protected function buildReport(): array
    {
        $orders = $this->constrainToPeriod(
            JobOrder::query(),
            'job_orders.created_at',
        );
        $statusCounts = (clone $orders)
            ->select('status', DB::raw('COUNT(*) AS order_count'))
            ->groupBy('status')
            ->pluck('order_count', 'status')
            ->map(fn (int|string $count): int => (int) $count);
        $totalOrders = $statusCounts->sum();

        $dispensingEvents = $this->constrainToPeriod(
            DispensingEvent::query()->whereHas('jobOrder'),
            'dispensing_events.dispensed_at',
        );
        $dispensedCount = (clone $dispensingEvents)->count();

        $cancelledOrders = $this->constrainToPeriod(
            JobOrder::query()
                ->where('status', JobOrderStatus::Cancelled->value)
                ->whereNotNull('cancelled_at'),
            'job_orders.cancelled_at',
        );
        $cancelledCount = (clone $cancelledOrders)->count();

        $averageMinutes = $this->constrainToPeriod(
            DispensingEvent::query()
                ->join('job_orders', 'job_orders.id', '=', 'dispensing_events.job_order_id')
                ->whereNull('job_orders.deleted_at')
                ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, job_orders.created_at, dispensing_events.dispensed_at)) AS average_minutes'),
            'dispensing_events.dispensed_at',
        )->value('average_minutes');

        $modeCounts = (clone $orders)
            ->select('fulfillment_mode', DB::raw('COUNT(*) AS order_count'))
            ->groupBy('fulfillment_mode')
            ->orderBy('fulfillment_mode')
            ->pluck('order_count', 'fulfillment_mode')
            ->map(fn (int|string $count): int => (int) $count);
        $supplierCounts = (clone $orders)
            ->select('uses_external_supplier', DB::raw('COUNT(*) AS order_count'))
            ->groupBy('uses_external_supplier')
            ->orderBy('uses_external_supplier')
            ->pluck('order_count', 'uses_external_supplier')
            ->map(fn (int|string $count): int => (int) $count);

        $statusLabels = [
            JobOrderStatus::Queued->value => 'Confirmed',
            JobOrderStatus::InProgress->value => 'Processing',
            JobOrderStatus::ReadyForDispensing->value => 'Ready for Pickup',
            JobOrderStatus::Dispensed->value => 'Completed',
            JobOrderStatus::Cancelled->value => 'Cancelled',
        ];
        $statusRows = collect($statusLabels)
            ->map(fn (string $label, string $status): array => [
                'label' => $label,
                'value' => $statusCounts->get($status, 0),
                'percentage' => $this->percentage($statusCounts->get($status, 0), $totalOrders),
            ])
            ->all();
        $modeRows = $modeCounts
            ->map(fn (int $count, string $mode): array => [
                'label' => Str::headline($mode),
                'value' => $count,
                'percentage' => $this->percentage($count, $totalOrders),
            ])
            ->values()
            ->all();
        $supplierRows = $supplierCounts
            ->map(fn (int $count, int|string|null $usesExternalSupplier): array => [
                'label' => match ((string) $usesExternalSupplier) {
                    '0' => 'In-house',
                    '1' => 'External supplier',
                    default => 'Unknown',
                },
                'value' => $count,
                'percentage' => $this->percentage($count, $totalOrders),
            ])
            ->values()
            ->all();
        $charts = $totalOrders > 0
            ? [
                $this->buildBarChart(
                    'order-status',
                    'Order pipeline',
                    'Current status of orders created in the selected period.',
                    array_column($statusRows, 'label'),
                    [[
                        'label' => 'Orders',
                        'data' => array_column($statusRows, 'value'),
                        'backgroundColor' => [
                            '#4F8DD7',
                            '#7C3AED',
                            '#16A34A',
                            '#DC2626',
                            '#64748B',
                        ],
                    ]],
                    horizontal: true,
                ),
                ...($supplierRows === [] ? [] : [$this->buildDoughnutChart(
                    'supplier-mode',
                    'Supplier mode',
                    'Orders grouped by in-house or external supplier fulfillment.',
                    array_column($supplierRows, 'label'),
                    array_column($supplierRows, 'value'),
                    'Orders',
                )]),
            ]
            : [];

        return [
            'stats' => [
                Stat::make('Orders created', number_format($totalOrders)),
                Stat::make('Dispensed', number_format($dispensedCount)),
                Stat::make('Cancelled', number_format($cancelledCount)),
                Stat::make('Avg. time to dispense', $this->formatAverageHours($averageMinutes)),
            ],
            'sections' => [
                [
                    'title' => 'Current status',
                    'description' => 'Current status of orders created in the selected period.',
                    'rows' => $statusRows,
                    'has_data' => $totalOrders > 0,
                ],
                [
                    'title' => 'Fulfillment mode',
                    'description' => 'Orders grouped by the fulfillment mode captured at creation.',
                    'rows' => $modeRows,
                    'has_data' => $totalOrders > 0,
                ],
                [
                    'title' => 'Supplier mode',
                    'description' => 'Orders grouped by whether an external supplier was required.',
                    'rows' => $supplierRows,
                    'has_data' => $totalOrders > 0,
                ],
            ],
            'charts' => $charts,
        ];
    }

    private function formatAverageHours(int|float|string|null $minutes): string
    {
        return number_format(((float) $minutes) / 60, 1).' hours';
    }
}
