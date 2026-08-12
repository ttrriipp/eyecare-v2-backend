<?php

namespace App\Filament\Resources\AppointmentRequests\Pages;

use App\Enums\AppointmentRequestStatus;
use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAppointmentRequests extends ListRecords
{
    protected static string $resource = AppointmentRequestResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            '/admin/appointments' => 'Appointments',
            'Requests',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getEmptyStateHeading(): string
    {
        return 'No appointment requests';
    }

    public function getEmptyStateDescription(): string
    {
        return 'When patients submit appointment requests from the mobile app, they will appear here for review.';
    }

    public function getEmptyStateActions(): array
    {
        return [
            Action::make('viewAppointments')
                ->label('View Appointments')
                ->url('/admin/appointments')
                ->icon('heroicon-o-calendar-days'),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', AppointmentRequestStatus::Pending)),

            'accepted' => Tab::make('Accepted')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', AppointmentRequestStatus::Accepted)),

            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', AppointmentRequestStatus::Rejected)),

            'cancelled' => Tab::make('Cancelled')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', AppointmentRequestStatus::Cancelled)),

            'expired' => Tab::make('Expired')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', AppointmentRequestStatus::Expired)),
        ];
    }
}
