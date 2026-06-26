<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Widgets\AppointmentCalendarWidget;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAppointments extends ListRecords
{
    protected static string $resource = AppointmentResource::class;

    public bool $showCalendar = false;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggleCalendar')
                ->label(fn (): string => $this->showCalendar ? 'Hide Calendar' : 'Show Calendar')
                ->icon(fn (): string => $this->showCalendar ? 'heroicon-o-calendar-days' : 'heroicon-o-calendar-days')
                ->color(fn (): string => $this->showCalendar ? 'gray' : 'info')
                ->action(fn () => $this->showCalendar = ! $this->showCalendar)
                ->livewireClickHandlerEnabled(),
            CreateAction::make(),
        ];
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
