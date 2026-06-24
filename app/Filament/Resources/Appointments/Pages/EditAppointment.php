<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Actions\Appointments\UpdateAppointmentStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditAppointment extends EditRecord
{
    protected static string $resource = AppointmentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Appointment $appointment */
        $appointment = $this->getRecord()->fresh(['status']);

        $newStatusId = (int) ($data['appointment_status_id'] ?? $appointment->appointment_status_id);

        if ($newStatusId === (int) $appointment->appointment_status_id) {
            return $data;
        }

        $statusName = AppointmentStatus::query()->findOrFail($newStatusId)->name;

        try {
            app(UpdateAppointmentStatus::class)->handle(
                appointment: $appointment,
                statusName: $statusName,
                staffNotes: $data['staff_notes'] ?? null,
            );
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                'data.appointment_status_id' => $e->errors()['status'] ?? ['Invalid status transition.'],
            ]);
        }

        unset($data['appointment_status_id'], $data['staff_notes']);
        $this->statusUpdateHandled = true;

        return $data;
    }

    protected bool $statusUpdateHandled = false;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bill_service')
                ->label('Bill Service')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->url(fn (): string => ServiceRecordResource::getUrl('create', [
                    'customer_id' => $this->getRecord()->customer_id,
                    'appointment_id' => $this->getRecord()->id,
                    'staff_id' => auth()->id(),
                ])),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if ($this->statusUpdateHandled) {
            $this->statusUpdateHandled = false;

            if ($data !== []) {
                $record->update($data);
            }

            return $record->fresh(['visitReason', 'status', 'customer']);
        }

        $record->update($data);

        return $record;
    }
}
