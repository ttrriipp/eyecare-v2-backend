<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Filament\Clusters\Reports\ReportsCluster;
use Carbon\CarbonImmutable;
use Exception;
use Filament\Pages\Page;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared shell for the admin-only aggregate reports.
 *
 * Concrete pages provide the report payload. The shell owns the date boundary,
 * URL state, authorization, presentation data, and export safety so every
 * report uses the same contract.
 */
abstract class ReportsClusterPage extends Page
{
    protected static ?string $cluster = ReportsCluster::class;

    protected string $view = 'filament.clusters.reports.pages.report';

    #[Url]
    public ?string $dateFrom = null;

    #[Url]
    public ?string $dateUntil = null;

    public ?string $dateInputFrom = null;

    public ?string $dateInputUntil = null;

    public ?string $activePreset = null;

    /**
     * @var array{stats: array<int, Stat>, sections: array<int, array<string, mixed>>}|null
     */
    private ?array $reportCache = null;

    public static function canAccess(): bool
    {
        return ReportsCluster::canAccess();
    }

    public function mount(): void
    {
        if ($this->dateFrom === null && $this->dateUntil === null) {
            $this->applyPreset('this_month');

            return;
        }

        $this->dateInputFrom = $this->dateFrom;
        $this->dateInputUntil = $this->dateUntil;
        $this->validateAppliedDateRange();
    }

    /**
     * Quick-select date ranges shared by every report.
     *
     * @return array<string, string>
     */
    public function getPresets(): array
    {
        return [
            'this_month' => 'This month',
            'last_month' => 'Last month',
            'last_30' => 'Last 30 days',
            'this_year' => 'This year',
            'all_time' => 'All time',
        ];
    }

    public function applyPreset(string $preset): void
    {
        if (! array_key_exists($preset, $this->getPresets())) {
            $this->resetValidation();
            $this->addError('dateInputFrom', 'Choose a valid report period.');

            return;
        }

        $today = CarbonImmutable::now($this->reportTimezone());

        [$from, $until] = match ($preset) {
            'last_month' => [
                $today->subMonthNoOverflow()->startOfMonth(),
                $today->subMonthNoOverflow()->endOfMonth(),
            ],
            'last_30' => [$today->subDays(29), $today],
            'this_year' => [$today->startOfYear(), $today],
            'all_time' => [null, null],
            default => [$today->startOfMonth(), $today],
        };

        $this->dateFrom = $from?->toDateString();
        $this->dateUntil = $until?->toDateString();
        $this->dateInputFrom = $this->dateFrom;
        $this->dateInputUntil = $this->dateUntil;
        $this->activePreset = $preset;
        $this->reportCache = null;
        $this->resetValidation();
    }

    public function applyDateRange(): void
    {
        $this->resetValidation();

        $validator = Validator::make(
            [
                'dateInputFrom' => $this->dateInputFrom,
                'dateInputUntil' => $this->dateInputUntil,
            ],
            [
                'dateInputFrom' => ['nullable', 'date_format:Y-m-d'],
                'dateInputUntil' => ['nullable', 'date_format:Y-m-d'],
            ],
            [
                'dateInputFrom.date_format' => 'Use the YYYY-MM-DD date format.',
                'dateInputUntil.date_format' => 'Use the YYYY-MM-DD date format.',
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $from = $this->parseDate($this->dateInputFrom);
        $until = $this->parseDate($this->dateInputUntil);

        if ($this->dateInputFrom !== null && $this->dateInputFrom !== '' && $from === null) {
            $this->addError('dateInputFrom', 'Enter a real calendar date.');
        }

        if ($this->dateInputUntil !== null && $this->dateInputUntil !== '' && $until === null) {
            $this->addError('dateInputUntil', 'Enter a real calendar date.');
        }

        if ($from !== null && $until !== null && $from->greaterThan($until)) {
            $this->addError('dateInputFrom', 'The start date must be on or before the end date.');
            $this->addError('dateInputUntil', 'The end date must be on or after the start date.');
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $this->dateFrom = filled($this->dateInputFrom) ? $this->dateInputFrom : null;
        $this->dateUntil = filled($this->dateInputUntil) ? $this->dateInputUntil : null;
        $this->activePreset = null;
        $this->reportCache = null;
    }

    /**
     * KPI stat cards for the report.
     *
     * @return array<int, Stat>
     */
    public function getStats(): array
    {
        return $this->getReport()['stats'];
    }

    /**
     * Breakdown sections for the report view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSections(): array
    {
        return $this->getReport()['sections'];
    }

    /**
     * Human-readable period shown in the report header and CSV metadata.
     */
    public function getPeriodLabel(): string
    {
        if ($this->dateFrom === null && $this->dateUntil === null) {
            return 'All time';
        }

        if ($this->dateFrom === null) {
            return "Through {$this->dateUntil}";
        }

        if ($this->dateUntil === null) {
            return "From {$this->dateFrom}";
        }

        return "{$this->dateFrom} – {$this->dateUntil}";
    }

    public function getTimezoneLabel(): string
    {
        return $this->reportTimezone();
    }

    /**
     * Export the current aggregate report without exposing patient data.
     */
    public function exportCsv(): ?StreamedResponse
    {
        if (! $this->dateRangeIsValid()) {
            $this->addError('dateInputFrom', 'Fix the report period before exporting.');

            return null;
        }

        $report = $this->getReport();
        $title = (string) static::getTitle();
        $filename = Str::slug($title, '_').'_'.now($this->reportTimezone())->format('Y_m_d').'.csv';

        return response()->streamDownload(function () use ($report, $title): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            $this->writeCsvRow($handle, [$title]);
            $this->writeCsvRow($handle, ['Period', $this->getPeriodLabel()]);
            $this->writeCsvRow($handle, ['Timezone', $this->getTimezoneLabel()]);

            $metricDefinitions = $this->getMetricDefinitions();

            if ($metricDefinitions !== []) {
                $this->writeCsvRow($handle, []);
                $this->writeCsvRow($handle, ['Metric definitions']);

                foreach ($metricDefinitions as $metric => $definition) {
                    $this->writeCsvRow($handle, [$metric, $definition]);
                }
            }

            $this->writeCsvRow($handle, []);
            $this->writeCsvRow($handle, ['Key metrics']);

            foreach ($report['stats'] as $stat) {
                $this->writeCsvRow($handle, [$stat->getLabel(), $stat->getValue()]);
            }

            foreach ($report['sections'] as $section) {
                $this->writeCsvRow($handle, []);
                $this->writeCsvRow($handle, [$section['title']]);
                $this->writeCsvRow($handle, ['Category', 'Value', 'Share']);

                foreach ($section['rows'] as $row) {
                    $this->writeCsvRow($handle, [
                        $row['label'],
                        $row['value'],
                        $row['percentage'].'%',
                    ]);
                }
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{
     *     reportStats: array<int, Stat>,
     *     reportSections: array<int, array<string, mixed>>,
     *     reportPresets: array<string, string>,
     *     reportPeriodLabel: string,
     *     reportTimezone: string,
     * }
     */
    protected function getViewData(): array
    {
        return [
            'reportStats' => $this->getStats(),
            'reportSections' => $this->getSections(),
            'reportPresets' => $this->getPresets(),
            'reportPeriodLabel' => $this->getPeriodLabel(),
            'reportTimezone' => $this->getTimezoneLabel(),
        ];
    }

    /**
     * Apply an inclusive calendar-date range as half-open timestamp bounds.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function constrainToPeriod(Builder $query, string $column): Builder
    {
        $from = $this->periodStart();
        $until = $this->periodEndExclusive();

        if ($from !== null) {
            $query->where($column, '>=', $from);
        }

        if ($until !== null) {
            $query->where($column, '<', $until);
        }

        return $query;
    }

    protected function periodStart(): ?CarbonImmutable
    {
        return $this->parseDate($this->dateFrom)?->startOfDay();
    }

    protected function periodEndExclusive(): ?CarbonImmutable
    {
        return $this->parseDate($this->dateUntil)?->addDay()->startOfDay();
    }

    protected function percentage(int|float $value, int|float $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) round(($value / $total) * 100);
    }

    protected function formatMoney(int|float|string $amount): string
    {
        return '₱'.number_format((float) $amount, 2);
    }

    protected function reportTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    /**
     * Definitions are included in exports so an aggregate value keeps its
     * cohort and exclusion rules when it leaves the admin panel.
     *
     * @return array<string, string>
     */
    public function getMetricDefinitions(): array
    {
        return [];
    }

    /**
     * @return array{stats: array<int, Stat>, sections: array<int, array<string, mixed>>}
     */
    abstract protected function buildReport(): array;

    /**
     * @return array{stats: array<int, Stat>, sections: array<int, array<string, mixed>>}
     */
    private function getReport(): array
    {
        if (! $this->dateRangeIsValid()) {
            return ['stats' => [], 'sections' => []];
        }

        return $this->reportCache ??= $this->buildReport();
    }

    private function dateRangeIsValid(): bool
    {
        $from = $this->parseDate($this->dateFrom);
        $until = $this->parseDate($this->dateUntil);

        return ($this->dateFrom === null || $from !== null)
            && ($this->dateUntil === null || $until !== null)
            && ($from === null || $until === null || $from->lessThanOrEqualTo($until));
    }

    private function validateAppliedDateRange(): void
    {
        $this->resetValidation();

        if ($this->dateFrom !== null && $this->parseDate($this->dateFrom) === null) {
            $this->addError('dateInputFrom', 'The report start date is invalid.');
        }

        if ($this->dateUntil !== null && $this->parseDate($this->dateUntil) === null) {
            $this->addError('dateInputUntil', 'The report end date is invalid.');
        }

        $from = $this->parseDate($this->dateFrom);
        $until = $this->parseDate($this->dateUntil);

        if ($from !== null && $until !== null && $from->greaterThan($until)) {
            $this->addError('dateInputFrom', 'The start date must be on or before the end date.');
            $this->addError('dateInputUntil', 'The end date must be on or after the start date.');
        }
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $this->reportTimezone());
        } catch (Exception) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * @param  resource  $handle
     * @param  array<int, mixed>  $values
     */
    private function writeCsvRow($handle, array $values): void
    {
        fputcsv($handle, array_map($this->safeCsvValue(...), $values));
    }

    private function safeCsvValue(mixed $value): string
    {
        $value = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}
