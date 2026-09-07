<?php

namespace App\Filament\Resources\AppointmentRescheduleRequests\Pages;

use App\Filament\Resources\AppointmentRescheduleRequests\AppointmentRescheduleRequestResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAppointmentRescheduleRequests extends ListRecords
{
    protected static string $resource = AppointmentRescheduleRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getEmptyStateHeading(): string
    {
        return 'No reschedule requests';
    }

    public function getEmptyStateDescription(): string
    {
        return 'Patient reschedule requests will appear here for staff review.';
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query): Builder => AppointmentRescheduleRequestResource::whereEffectivePending($query)),
            'history' => Tab::make('History')
                ->modifyQueryUsing(fn (Builder $query): Builder => AppointmentRescheduleRequestResource::whereEffectiveExpired($query)),
        ];
    }
}
