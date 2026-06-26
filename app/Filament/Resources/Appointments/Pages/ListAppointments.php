<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Widgets\AppointmentCalendarWidget;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;

class ListAppointments extends ListRecords
{
    protected static string $resource = AppointmentResource::class;

    public bool $showCalendar = false;

    public function toggleView(bool $calendar): void
    {
        $this->showCalendar = $calendar;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components(
            $this->showCalendar
                ? [Livewire::make(AppointmentCalendarWidget::class)]
                : [
                    $this->getTabsContentComponent(),
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                    EmbeddedTable::make(),
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
                ]
        );
    }

    public function table(Table $table): Table
    {
        return $table->toolbarActions([
            Action::make('tableView')
                ->label('')
                ->icon('heroicon-o-table-cells')
                ->tooltip('Table view')
                ->color(fn (): string => $this->showCalendar ? 'gray' : 'primary')
                ->action(fn () => $this->toggleView(false))
                ->livewireClickHandlerEnabled(),
            Action::make('calendarView')
                ->label('')
                ->icon('heroicon-o-calendar-days')
                ->tooltip('Calendar view')
                ->color(fn (): string => $this->showCalendar ? 'primary' : 'gray')
                ->action(fn () => $this->toggleView(true))
                ->livewireClickHandlerEnabled(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
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
