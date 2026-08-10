<?php

namespace App\Filament\Clusters\Availability\Resources\AppointmentTypes\Pages;

use App\Filament\Clusters\Availability\Resources\AppointmentTypes\AppointmentTypesResource;
use App\Models\Appointment;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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
        return [
            Action::make('delete')
                ->label('Delete')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function () {
                    $hasAppointments = Appointment::where('appointment_type_id', $this->record->id)->exists();

                    if ($hasAppointments) {
                        Notification::make()
                            ->warning()
                            ->title('Cannot delete')
                            ->body('This appointment type is referenced by existing appointments. Deactivate it instead.')
                            ->send();

                        return;
                    }

                    $this->record->delete();

                    $this->redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }
}
