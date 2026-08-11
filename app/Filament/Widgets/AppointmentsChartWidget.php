<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatusName;
use App\Models\Appointment;
use App\Models\Role;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class AppointmentsChartWidget extends ChartWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Appointment trend';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 4;

    public static function canView(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return $user->loadMissing('roles')->roles->pluck('name')->contains(Role::Admin);
    }

    public function getDescription(): ?string
    {
        return 'Daily non-cancelled appointments over the last 30 days.';
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
        $start = today()->subDays($days - 1)->startOfDay();
        $end = today()->addDay()->startOfDay();
        $cacheKey = "dashboard.appointments_chart.{$start->toDateString()}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($days, $start, $end): array {

            $countsByDay = Appointment::query()
                ->whereHas('status', fn (Builder $query): Builder => $query->whereNotIn('name', [
                    AppointmentStatusName::Cancelled->value,
                    AppointmentStatusName::NoShow->value,
                ]))
                ->where('scheduled_at', '>=', $start)
                ->where('scheduled_at', '<', $end)
                ->selectRaw('DATE(scheduled_at) as appointment_date, COUNT(*) as appointment_count')
                ->groupBy('appointment_date')
                ->pluck('appointment_count', 'appointment_date');

            $labels = [];
            $data = [];

            for ($offset = 0; $offset < $days; $offset++) {
                $date = $start->copy()->addDays($offset);
                $labels[] = $date->format('M j');
                $data[] = (int) $countsByDay->get($date->toDateString(), 0);
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
        });
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
