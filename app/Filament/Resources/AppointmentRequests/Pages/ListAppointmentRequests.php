<?php

namespace App\Filament\Resources\AppointmentRequests\Pages;

use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use Filament\Resources\Pages\ListRecords;

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
}
