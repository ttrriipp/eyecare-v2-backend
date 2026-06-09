<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Actions\Appointments\UpdateAppointmentStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class EditAppointment extends EditRecord
{
    protected static string $resource = AppointmentResource::class;

    protected bool $statusUpdateHandled = false;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Appointment $appointment */
        $appointment = $this->getRecord()->fresh();

        $newStatusId = (int) ($data['appointment_status_id'] ?? $appointment->appointment_status_id);

        if ($newStatusId === (int) $appointment->appointment_status_id) {
            return $data;
        }

        $statusName = AppointmentStatus::query()->findOrFail($newStatusId)->name;

        app(UpdateAppointmentStatus::class)->handle(
            appointment: $appointment,
            statusName: $statusName,
            scheduledAt: $statusName === 'rescheduled' && isset($data['scheduled_at'])
                ? Carbon::parse($data['scheduled_at'])
                : null,
            staffNotes: $data['staff_notes'] ?? null,
        );

        unset($data['appointment_status_id'], $data['staff_notes']);

        if ($statusName !== 'rescheduled') {
            unset($data['scheduled_at']);
        }

        $this->statusUpdateHandled = true;

        return $data;
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
