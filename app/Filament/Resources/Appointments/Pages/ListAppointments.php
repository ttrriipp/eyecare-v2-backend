<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Widgets\AppointmentStatsWidget;
use App\Models\AppointmentRequest;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAppointments extends ListRecords
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')
                ->label('Calendar')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->outlined()
                ->url(AppointmentResource::getUrl('calendar')),
            Action::make('requests')
                ->label(function () {
                    $count = AppointmentRequest::where('status', 'pending')
                        ->where('expires_at', '>', now())
                        ->count();

                    return $count > 0 ? "Requests ({$count})" : 'Requests';
                })
                ->icon('heroicon-o-clock')
                ->color('warning')
                ->url(AppointmentRequestResource::getUrl('index')),
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [AppointmentStatsWidget::class];
    }

    public function getTabs(): array
    {
        $statuses = ['scheduled', 'checked_in', 'fulfilled', 'no_show', 'cancelled'];

        $tabs = [
            'all' => Tab::make('All'),
        ];

        foreach ($statuses as $status) {
            $label = ucwords(str_replace('_', ' ', $status));
            $tabs[$status] = Tab::make($label)
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereHas('status', fn (Builder $q) => $q->where('name', $status))
                );
        }

        return $tabs;
    }
}
