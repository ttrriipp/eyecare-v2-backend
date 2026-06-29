<?php

namespace App\Filament\Pages\Reports;

use App\Models\Appointment;
use App\Models\AppointmentStatus;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use UnitEnum;

class AppointmentsReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = null;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Appointments Report';

    protected string $view = 'filament.pages.reports.report';

    #[Url]
    public ?string $dateFrom = null;

    #[Url]
    public ?string $dateUntil = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->dateFrom ??= now()->startOfMonth()->toDateString();
        $this->dateUntil ??= now()->toDateString();
    }

    public function filtersSchema(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('dateFrom')->label('From')->default(now()->startOfMonth()),
            DatePicker::make('dateUntil')->label('Until')->default(now()),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Appointments Report';
    }

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
