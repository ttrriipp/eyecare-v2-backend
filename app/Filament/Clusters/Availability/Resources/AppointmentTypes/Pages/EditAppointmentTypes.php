<?php

namespace App\Filament\Clusters\Availability\Resources\AppointmentTypes\Pages;

use App\Filament\Clusters\Availability\Resources\AppointmentTypes\AppointmentTypesResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditAppointmentTypes extends EditRecord
{
    protected static string $resource = AppointmentTypesResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::SevenExtraLarge;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
