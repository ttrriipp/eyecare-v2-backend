<?php

namespace App\Filament\Resources\AppointmentRequests\Pages;

use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Filament\Resources\AppointmentRequests\Widgets\AppointmentRequestStatsWidget;
use App\Filament\Resources\Appointments\AppointmentResource;
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
            AppointmentResource::getUrl('index') => 'Appointments',
            'Requests',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [AppointmentRequestStatsWidget::class];
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
                ->url(AppointmentResource::getUrl('index'))
                ->icon('heroicon-o-calendar-days'),
        ];
    }

    /**
     * Tabs mirror the actual triage workflow rather than raw status values.
     * "Needs Link" and "Needs Review" are the two states staff act on; every
     * resolved outcome — accepted, rejected, cancelled, expired — lands in
     * "Resolved", where the status column and filter distinguish them further.
     *
     * "All" stays first/default so the landing view never hides a request
     * behind a workflow tab a new user hasn't discovered yet.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'needs_link' => Tab::make('Needs Link')
                ->modifyQueryUsing(fn (Builder $query) => $query->needsLink()),

            'needs_review' => Tab::make('Needs Review')
                ->modifyQueryUsing(fn (Builder $query) => $query->needsReview()),

            'resolved' => Tab::make('Resolved')
                ->modifyQueryUsing(fn (Builder $query) => $query->resolvedForTriage()),
        ];
    }
}
