<?php

namespace App\Filament\Pages\Reports;

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AppointmentsReport extends BaseReport
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Appointments Report';

    /**
     * @return array<int, Stat>
     */
    public function getStats(): array
    {
        $query = Appointment::query()
            ->when($this->dateFrom, fn ($q) => $q->whereDate('scheduled_at', '>=', $this->dateFrom))
            ->when($this->dateUntil, fn ($q) => $q->whereDate('scheduled_at', '<=', $this->dateUntil));

        $total = (clone $query)->count();
        $completed = (clone $query)->whereHas('status', fn ($q) => $q->where('name', 'completed'))->count();
        $rate = $total > 0 ? round(($completed / $total) * 100) : 0;

        return [
            Stat::make('Total appointments', number_format($total)),
            Stat::make('Completed', number_format($completed))->color('success'),
            Stat::make('Completion rate', $rate.'%')->color($rate >= 70 ? 'success' : 'warning'),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function getBreakdown(): array
    {
        $query = Appointment::query()
            ->when($this->dateFrom, fn ($q) => $q->whereDate('scheduled_at', '>=', $this->dateFrom))
            ->when($this->dateUntil, fn ($q) => $q->whereDate('scheduled_at', '<=', $this->dateUntil));

        $statuses = AppointmentStatus::query()->pluck('name', 'id');
        $counts = (clone $query)->selectRaw('appointment_status_id, count(*) as total')
            ->groupBy('appointment_status_id')
            ->pluck('total', 'appointment_status_id');

        $breakdown = [];
        foreach ($statuses as $id => $name) {
            $breakdown[ucfirst($name)] = (int) ($counts[$id] ?? 0);
        }

        return $breakdown;
    }
}
