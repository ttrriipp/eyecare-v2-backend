<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Widgets\AppointmentCalendarWidget;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class ListAppointments extends ListRecords
{
    protected static string $resource = AppointmentResource::class;

    public bool $showCalendar = false;

    public function boot(): void
    {
        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_END,
            fn (): View => view('filament.appointments.view-toggle', ['showCalendar' => $this->showCalendar]),
            scopes: static::class,
        );
    }

    public function toggleView(bool $calendar): void
    {
        $this->showCalendar = $calendar;
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function getHeaderWidgets(): array
    {
        return $this->showCalendar ? [AppointmentCalendarWidget::class] : [];
    }

    public function getTabs(): array
    {
        $statuses = ['pending', 'confirmed', 'rescheduled', 'completed', 'cancelled'];

        $tabs = ['all' => Tab::make('All')];

        foreach ($statuses as $status) {
            $label = ucwords(str_replace('_', ' ', $status));
            $tabs[$status] = Tab::make($label)
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas(
                    'status',
                    fn (Builder $q) => $q->where('name', $status)
                ));
        }

        return $tabs;
    }
}
