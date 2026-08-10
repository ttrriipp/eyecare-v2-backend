<?php

namespace App\Filament\Clusters\Availability\Resources\AppointmentTypes\Pages;

use App\Filament\Clusters\Availability\Resources\AppointmentTypes\AppointmentTypesResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateAppointmentTypes extends CreateRecord
{
    protected static string $resource = AppointmentTypesResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::SevenExtraLarge;
    }
}
