<?php

namespace App\Filament\Widgets;

use App\Models\Appointment;
use Filament\Widgets\ChartWidget;

class AppointmentsChartWidget extends ChartWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Appointment volume';

    protected ?string $maxHeight = '300px';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public function getDescription(): ?string
    {
        return 'Daily appointments over the last 30 days.';
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $days = 30;
        $start = today()->subDays($days - 1);

        $countsByDay = Appointment::query()
            ->whereHas('status', fn ($query) => $query->where('name', '!=', 'cancelled'))
            ->whereDate('scheduled_at', '>=', $start)
            ->whereDate('scheduled_at', '<=', today())
            ->get(['scheduled_at'])
            ->groupBy(fn (Appointment $appointment): string => $appointment->scheduled_at->toDateString())
            ->map
            ->count();

        $labels = [];
        $data = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->copy()->addDays($offset);
            $labels[] = $date->format('M j');
            $data[] = $countsByDay->get($date->toDateString(), 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Appointments',
                    'data' => $data,
                    'borderColor' => '#4F8DD7',
                    'backgroundColor' => 'rgba(79, 141, 215, 0.12)',
                    'fill' => true,
                    'tension' => 0.35,
                    'pointRadius' => 2,
                    'pointHoverRadius' => 4,
                    'pointBackgroundColor' => '#4F8DD7',
                ],
            ],
            'labels' => $labels,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
            ],
        ];
    }
}
