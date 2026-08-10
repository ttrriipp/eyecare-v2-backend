<?php

namespace App\Filament\Clusters\Availability\Resources\AppointmentTypes\Pages;

use App\Filament\Clusters\Availability\Resources\AppointmentTypes\AppointmentTypesResource;
use App\Models\Appointment;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;

class EditAppointmentTypes extends EditRecord
{
    protected static string $resource = AppointmentTypesResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::SevenExtraLarge;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function () {
                    $hasAppointments = Appointment::where('appointment_type_id', $this->record->id)->exists();

                    if ($hasAppointments) {
                        throw ValidationException::withMessages([
                            'appointment_type' => ['This appointment type cannot be deleted because it is referenced by existing appointments. Deactivate it instead.'],
                        ]);
                    }
                }),
        ];
    }
}
