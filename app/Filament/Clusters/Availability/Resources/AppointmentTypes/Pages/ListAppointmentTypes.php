<?php

namespace App\Filament\Clusters\Availability\Resources\AppointmentTypes\Pages;

use App\Filament\Clusters\Availability\Resources\AppointmentTypes\AppointmentTypesResource;
use Filament\Resources\Pages\ListRecords;

class ListAppointmentTypes extends ListRecords
{
    protected static string $resource = AppointmentTypesResource::class;
}
