<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Actions\Appointments\RescheduleAppointment;
use App\Actions\Appointments\UpdateAppointmentStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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
            Action::make('reschedule')
                ->label('Reschedule')
                ->icon('heroicon-o-calendar-days')
                ->color('warning')
                ->visible(fn (): bool => in_array(
                    $this->getRecord()->status?->name,
                    ['pending', 'confirmed'],
                    true,
                ))
                ->schema([
                    DateTimePicker::make('scheduled_at')
                        ->label('New appointment date and time')
                        ->required()
                        ->native(false)
                        ->seconds(false)
                        ->minutesStep(30)
                        ->displayFormat('M d, Y h:i A')
                        ->prefixIcon('heroicon-o-calendar-days')
                        ->minDate(now())
                        ->after('now'),
                ])
                ->action(function (array $data): void {
                    /** @var Appointment $appointment */
                    $appointment = $this->getRecord()->fresh(['status']);
                    try {
                        app(RescheduleAppointment::class)->handle(
                            appointment: $appointment,
                            scheduledAt: Carbon::parse($data['scheduled_at']),
                            customerInitiated: false,
                        );
                        Notification::make()->title('Appointment rescheduled')->success()->send();
                        $this->refreshFormData(['appointment_status_id', 'scheduled_at']);
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot reschedule appointment.';
                        Notification::make()->title('Cannot reschedule')->body($message)->danger()->send();
                    }
                }),
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

            return $record->fresh(['visitReason', 'status', 'customer', 'optometrist']);
        }

        $record->update($data);

        return $record;
    }
}
