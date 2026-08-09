<?php

namespace App\Filament\Clusters\Availability\Resources\AppointmentTypes\Pages;

use App\Filament\Clusters\Availability\Resources\AppointmentTypes\AppointmentTypesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAppointmentTypes extends ListRecords
{
    protected static string $resource = AppointmentTypesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
