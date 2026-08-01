<?php

namespace App\Filament\Resources\AppointmentRequests\Pages;

use App\Actions\Appointments\AcceptAppointmentRequest;
use App\Actions\Appointments\RejectAppointmentRequest;
use App\Enums\AppointmentRequestStatus;
use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Models\AppointmentType;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewAppointmentRequest extends ViewRecord
{
    protected static string $resource = AppointmentRequestResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            '/admin/appointments' => 'Appointments',
            '/admin/appointment-requests' => 'Requests',
            $this->record->request_number,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('accept')
                ->label('Accept Request')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->status === AppointmentRequestStatus::Pending && $this->record->patient_id !== null)
                ->schema([
                    Select::make('appointment_type_id')
                        ->label('Appointment Type')
                        ->options(AppointmentType::pluck('name', 'id'))
                        ->required(),
                    Textarea::make('reason')
                        ->label('Staff Notes (optional)'),
                ])
                ->action(function (array $data) {
                    $appointment = app(AcceptAppointmentRequest::class)->handle(
                        request: $this->record,
                        reviewer: auth()->user(),
                        appointmentTypeId: $data['appointment_type_id'],
                    );

                    $this->record->refresh();
                    $this->notify('success', "Appointment {$appointment->appointment_number} created.");
                }),

            Action::make('reject')
                ->label('Reject Request')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->status === AppointmentRequestStatus::Pending)
                ->schema([
                    Textarea::make('reason')
                        ->label('Rejection Reason')
                        ->required(),
                ])
                ->requiresConfirmation()
                ->action(function (array $data) {
                    app(RejectAppointmentRequest::class)->handle(
                        request: $this->record,
                        reviewer: auth()->user(),
                        reason: $data['reason'],
                    );

                    $this->record->refresh();
                    $this->notify('success', 'Request rejected.');
                }),
        ];
    }
}
