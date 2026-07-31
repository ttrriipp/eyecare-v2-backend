<?php

namespace App\Filament\Resources\AppointmentRequests\Pages;

use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAppointmentRequests extends ListRecords
{
    protected static string $resource = AppointmentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
