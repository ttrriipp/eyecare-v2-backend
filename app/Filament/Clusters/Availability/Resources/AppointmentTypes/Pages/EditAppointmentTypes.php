<?php

namespace App\Filament\Clusters\Availability\Resources\AppointmentTypes\Pages;

use App\Filament\Clusters\Availability\Resources\AppointmentTypes\AppointmentTypesResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAppointmentTypes extends EditRecord
{
    protected static string $resource = AppointmentTypesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
