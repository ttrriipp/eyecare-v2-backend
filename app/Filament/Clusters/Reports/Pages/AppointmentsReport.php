<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Enums\AppointmentStatusName;
use App\Models\Appointment;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppointmentsReport extends ReportsClusterPage
{
    protected static ?string $title = 'Appointments';

    protected static ?string $navigationLabel = 'Appointments';

    protected static ?int $navigationSort = 2;

    /**
     * @return array<string, string>
     */
    public function getMetricDefinitions(): array
    {
        return [
            'Appointments' => 'Appointments scheduled in the selected period.',
            'Terminal outcomes' => 'Fulfilled, cancelled, and no-show appointments in the current-status cohort.',
            'Fulfillment rate' => 'Fulfilled appointments divided by terminal outcomes; zero when the denominator is empty.',
            'No-show rate' => 'No-show appointments divided by terminal outcomes; zero when the denominator is empty.',
        ];
    }

    /**
     * @return array{stats: array<int, Stat>, sections: array<int, array<string, mixed>>}
     */
    protected function buildReport(): array
    {
        $appointments = $this->constrainToPeriod(
            Appointment::query(),
            'appointments.scheduled_at',
        );

        $statusCounts = (clone $appointments)
            ->join('appointment_statuses', 'appointment_statuses.id', '=', 'appointments.appointment_status_id')
            ->select('appointment_statuses.name', DB::raw('COUNT(*) AS appointment_count'))
            ->groupBy('appointment_statuses.name')
            ->pluck('appointment_count', 'name')
            ->map(fn (int|string $count): int => (int) $count);
        $totalAppointments = $statusCounts->sum();

        $terminalStatuses = [
            AppointmentStatusName::Fulfilled->value,
            AppointmentStatusName::Cancelled->value,
            AppointmentStatusName::NoShow->value,
        ];
        $terminalAppointments = $statusCounts->only($terminalStatuses)->sum();
        $fulfilledAppointments = $statusCounts->get(AppointmentStatusName::Fulfilled->value, 0);
        $noShowAppointments = $statusCounts->get(AppointmentStatusName::NoShow->value, 0);

        $sourceCounts = (clone $appointments)
            ->select('source', DB::raw('COUNT(*) AS appointment_count'))
            ->groupBy('source')
            ->orderBy('source')
            ->pluck('appointment_count', 'source')
            ->map(fn (int|string $count): int => (int) $count);

        $typeCounts = (clone $appointments)
            ->join('appointment_types', 'appointment_types.id', '=', 'appointments.appointment_type_id')
            ->select('appointment_types.name', DB::raw('COUNT(*) AS appointment_count'))
            ->groupBy('appointment_types.id', 'appointment_types.name')
            ->orderBy('appointment_types.name')
            ->pluck('appointment_count', 'name')
            ->map(fn (int|string $count): int => (int) $count);

        $outcomeRows = collect(AppointmentStatusName::cases())
            ->map(fn (AppointmentStatusName $status): array => [
                'label' => $status->getLabel(),
                'value' => $statusCounts->get($status->value, 0),
                'percentage' => $this->percentage($statusCounts->get($status->value, 0), $totalAppointments),
            ])
            ->all();
        $sourceRows = $sourceCounts
            ->map(fn (int $count, ?string $source): array => [
                'label' => filled($source) ? Str::headline($source) : 'Unknown',
                'value' => $count,
                'percentage' => $this->percentage($count, $totalAppointments),
            ])
            ->values()
            ->all();
        $typeRows = $typeCounts
            ->map(fn (int $count, string $type): array => [
                'label' => $type,
                'value' => $count,
                'percentage' => $this->percentage($count, $totalAppointments),
            ])
            ->values()
            ->all();
        $charts = $totalAppointments > 0
            ? [
                $this->buildDoughnutChart(
                    'appointment-outcomes',
                    'Appointment outcomes',
                    'Current outcomes for appointments scheduled in the selected period.',
                    array_column($outcomeRows, 'label'),
                    array_column($outcomeRows, 'value'),
                    'Appointments',
                ),
                ...($typeRows === [] ? [] : [$this->buildBarChart(
                    'appointment-types',
                    'Appointments by type',
                    'Appointment volume grouped by the selected appointment type.',
                    array_column($typeRows, 'label'),
                    [[
                        'label' => 'Appointments',
                        'data' => array_column($typeRows, 'value'),
                    ]],
                )]),
            ]
            : [];

        return [
            'stats' => [
                Stat::make('Appointments', number_format($totalAppointments)),
                Stat::make('Terminal outcomes', number_format($terminalAppointments)),
                Stat::make('Fulfillment rate', $this->percentage($fulfilledAppointments, $terminalAppointments).'%'),
                Stat::make('No-show rate', $this->percentage($noShowAppointments, $terminalAppointments).'%'),
            ],
            'sections' => [
                [
                    'title' => 'Current outcome',
                    'description' => 'Current status of appointments scheduled in the selected period.',
                    'rows' => $outcomeRows,
                    'has_data' => $totalAppointments > 0,
                ],
                [
                    'title' => 'Appointment source',
                    'description' => 'Appointments grouped by their recorded booking source.',
                    'rows' => $sourceRows,
                    'has_data' => $totalAppointments > 0,
                ],
                [
                    'title' => 'Appointment type',
                    'description' => 'Appointments grouped by the selected appointment type.',
                    'rows' => $typeRows,
                    'has_data' => $totalAppointments > 0,
                ],
            ],
            'charts' => $charts,
        ];
    }
}
