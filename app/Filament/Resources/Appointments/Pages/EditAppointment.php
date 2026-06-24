<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Actions\Appointments\UpdateAppointmentStatus;
use App\Actions\Billing\AddServiceToBilling;
use App\Actions\Billing\CreateServiceBilling;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Models\AppointmentStatus;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
                ->schema([
                    Select::make('service_id')
                        ->label('Service')
                        ->options(fn () => Service::query()->active()->pluck('name', 'id'))
                        ->required()
                        ->searchable(),
                    TextInput::make('amount')
                        ->label('Amount')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('₱')
                        ->helperText('Leave blank to use default price.'),
                    Select::make('staff_id')
                        ->label('Performed by')
                        ->options(fn () => User::query()
                            ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'admin']))
                            ->pluck('name', 'id')
                        )
                        ->required()
                        ->default(fn () => auth()->id()),
                    DateTimePicker::make('performed_at')
                        ->label('Performed at')
                        ->required()
                        ->default(now()),
                ])
                ->action(function (array $data): void {
                    /** @var Appointment $appointment */
                    $appointment = $this->getRecord();
                    if (empty($data['amount'])) {
                        unset($data['amount']);
                    }
                    $data['appointment_id'] = $appointment->id;

                    // Add to existing billing if the appointment's order has one
                    $existingBilling = $appointment->order?->billing;
                    if ($existingBilling) {
                        $data['customer_id'] = $appointment->customer_id;
                        app(AddServiceToBilling::class)->handle($existingBilling, $data);
                    } else {
                        $data['customer_id'] = $appointment->customer_id;
                        app(CreateServiceBilling::class)->handle($data);
                    }
                })
                ->successNotificationTitle('Service billed'),
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
