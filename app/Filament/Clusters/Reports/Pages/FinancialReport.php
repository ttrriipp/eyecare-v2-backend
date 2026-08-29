<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Enums\BillingItemSourceKind;
use App\Enums\BillingRecordStatus;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use App\Models\BillingRecordItem;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinancialReport extends ReportsClusterPage
{
    protected static ?string $title = 'Financial';

    protected static ?string $navigationLabel = 'Financial';

    protected static ?int $navigationSort = 1;

    /**
     * @return array<string, string>
     */
    public function getMetricDefinitions(): array
    {
        return [
            'Net billed' => 'Sum of non-voided billing record totals by recorded date.',
            'Discounts' => 'Sum of discounts on the same non-voided billing record cohort.',
            'Collections' => 'Posted payments recorded in the period; reversed payments and voided bills are excluded.',
            'Open balance (snapshot)' => 'Current balance_due snapshot for the billing record cohort; not historical aging.',
        ];
    }

    /**
     * @return array{stats: array<int, Stat>, sections: array<int, array<string, mixed>>}
     */
    protected function buildReport(): array
    {
        $billingRecords = $this->constrainToPeriod(
            BillingRecord::query()->where('status', '!=', BillingRecordStatus::Voided->value),
            'billing_records.recorded_at',
        );
        $summary = (clone $billingRecords)
            ->selectRaw(
                'COALESCE(SUM(total_amount), 0) AS net_billed,
                 COALESCE(SUM(discount_amount), 0) AS discounts,
                 COALESCE(SUM(balance_due), 0) AS open_balance',
            )
            ->first();

        $payments = $this->constrainToPeriod(
            BillingPayment::query()
                ->where('status', 'posted')
                ->whereHas('billingRecord', fn (Builder $query): Builder => $query
                    ->where('status', '!=', BillingRecordStatus::Voided->value)),
            'billing_payments.recorded_at',
        );
        $collections = (clone $payments)->sum('amount');

        $statusCounts = (clone $billingRecords)
            ->select('status', DB::raw('COUNT(*) AS record_count'))
            ->groupBy('status')
            ->pluck('record_count', 'status')
            ->map(fn (int|string $count): int => (int) $count);
        $recordTotal = $statusCounts->sum();

        $paymentAmounts = (clone $payments)
            ->select('payment_method', DB::raw('SUM(amount) AS total_amount'))
            ->groupBy('payment_method')
            ->orderBy('payment_method')
            ->pluck('total_amount', 'payment_method')
            ->map(fn (int|float|string $amount): float => (float) $amount);
        $paymentTotal = $paymentAmounts->sum();

        $sourceAmounts = BillingRecordItem::query()
            ->whereHas('billingRecord', function (Builder $query): void {
                $query->where('status', '!=', BillingRecordStatus::Voided->value);
                $this->constrainToPeriod($query, 'billing_records.recorded_at');
            })
            ->select('source_kind', DB::raw('SUM(amount) AS total_amount'))
            ->groupBy('source_kind')
            ->pluck('total_amount', 'source_kind')
            ->map(fn (int|float|string $amount): float => (float) $amount);
        $sourceTotal = $sourceAmounts->sum();

        $statusRows = collect([
            BillingRecordStatus::Unpaid,
            BillingRecordStatus::PartiallyPaid,
            BillingRecordStatus::Paid,
        ])->map(fn (BillingRecordStatus $status): array => [
            'label' => $status->getLabel(),
            'value' => $statusCounts->get($status->value, 0),
            'percentage' => $this->percentage($statusCounts->get($status->value, 0), $recordTotal),
        ])->all();

        $paymentRows = $paymentAmounts
            ->map(fn (float $amount, ?string $method): array => [
                'label' => filled($method) ? Str::headline($method) : 'Unknown',
                'value' => $this->formatMoney($amount),
                'percentage' => $this->percentage($amount, $paymentTotal),
            ])
            ->values()
            ->all();

        $sourceLabels = [
            BillingItemSourceKind::OpticalOrder->value => 'Optical Order',
            BillingItemSourceKind::Quotation->value => 'Quotation Service',
            BillingItemSourceKind::Encounter->value => 'Consultation',
            BillingItemSourceKind::DirectService->value => 'Direct Charge',
        ];
        $sourceRows = collect($sourceLabels)
            ->map(function (string $label, string $sourceKind) use ($sourceAmounts, $sourceTotal): ?array {
                $amount = $sourceAmounts->get($sourceKind, 0);

                if ($amount <= 0) {
                    return null;
                }

                return [
                    'label' => $label,
                    'value' => $this->formatMoney($amount),
                    'percentage' => $this->percentage($amount, $sourceTotal),
                ];
            })
            ->filter()
            ->values();
        $sourceRows = $sourceRows
            ->merge(
                $sourceAmounts->except(array_keys($sourceLabels))->map(fn (float $amount): array => [
                    'label' => 'Unknown source',
                    'value' => $this->formatMoney($amount),
                    'percentage' => $this->percentage($amount, $sourceTotal),
                ])->values(),
            )
            ->values()
            ->all();

        return [
            'stats' => [
                Stat::make('Net billed', $this->formatMoney($summary?->net_billed ?? 0)),
                Stat::make('Discounts', $this->formatMoney($summary?->discounts ?? 0)),
                Stat::make('Collections', $this->formatMoney($collections)),
                Stat::make('Open balance (snapshot)', $this->formatMoney($summary?->open_balance ?? 0)),
            ],
            'sections' => [
                [
                    'title' => 'Bill status',
                    'description' => 'Current status of non-voided bills recorded in the selected period.',
                    'rows' => $statusRows,
                    'has_data' => $recordTotal > 0,
                ],
                [
                    'title' => 'Charge source',
                    'description' => 'Itemized charge amounts grouped by their recorded source.',
                    'rows' => $sourceRows,
                    'has_data' => $sourceTotal > 0,
                ],
                [
                    'title' => 'Payment method',
                    'description' => 'Posted collection amounts grouped by payment method; reversed payments are excluded.',
                    'rows' => $paymentRows,
                    'has_data' => $paymentTotal > 0,
                ],
            ],
        ];
    }
}
