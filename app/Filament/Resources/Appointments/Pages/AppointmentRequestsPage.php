<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\AppointmentRequests\Tables\AppointmentRequestsTable;
use App\Filament\Resources\Appointments\AppointmentResource;
use Filament\Resources\Pages\Page;
use Filament\Tables\Table;

class AppointmentRequestsPage extends Page
{
    protected static string $resource = AppointmentResource::class;

    protected string $view = 'filament.resources.appointments.pages.appointment-requests';

    protected static ?string $title = 'Appointment Requests';

    protected static ?string $navigationLabel = 'Requests';

    protected static ?int $navigationSort = 1;

    public function table(Table $table): Table
    {
        return AppointmentRequestsTable::configure($table);
    }

    public function getHeaderActions(): array
    {
        return [];
    }
}
