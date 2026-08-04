<?php

namespace App\Filament\Resources\AppointmentRequests\Pages;

use App\Actions\Appointments\AcceptAppointmentRequest;
use App\Actions\Appointments\LinkAppointmentRequestToPatient;
use App\Actions\Appointments\RejectAppointmentRequest;
use App\Actions\PatientAccounts\RankPatientCandidates;
use App\Enums\AppointmentRequestStatus;
use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Models\AppointmentType;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
            Action::make('linkToPatient')
                ->label('Link to Patient')
                ->icon('heroicon-o-link')
                ->color('primary')
                ->visible(fn () => $this->record->status === AppointmentRequestStatus::Pending && $this->record->patient_id === null)
                ->schema(function (): array {
                    $candidateOptions = [];

                    if ($this->record->hasIdentitySnapshot()) {
                        $candidateOptions = app(RankPatientCandidates::class)
                            ->fromSnapshot($this->record->encrypted_identity_snapshot)
                            ->mapWithKeys(fn (array $candidate): array => [
                                $candidate['patient']->id => "{$candidate['patient']->full_name} ({$candidate['patient']->patient_number}) — ".Str::headline($candidate['strength']).' match',
                            ])
                            ->toArray();
                    }

                    $otherOptions = Patient::query()
                        ->whereNotIn('id', array_keys($candidateOptions))
                        ->get()
                        ->mapWithKeys(fn (Patient $p): array => [
                            $p->id => "{$p->full_name} ({$p->patient_number})",
                        ])
                        ->toArray();

                    return [
                        Select::make('patient_id')
                            ->label('Patient')
                            ->options(array_filter([
                                'Candidate Matches' => $candidateOptions,
                                'All Patients' => $otherOptions,
                            ]))
                            ->searchable()
                            ->required()
                            ->helperText('Select which clinical record this request belongs to.'),
                    ];
                })
                ->action(function (array $data): void {
                    try {
                        $patient = Patient::findOrFail($data['patient_id']);

                        app(LinkAppointmentRequestToPatient::class)->handle(
                            request: $this->record,
                            patient: $patient,
                        );

                        $this->record->refresh();
                        Notification::make()->title('Request linked to patient')->success()->send();
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot link request.';
                        Notification::make()->title('Cannot link request')->body($message)->danger()->send();
                    }
                }),

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
                ->action(function (array $data): void {
                    try {
                        $appointment = app(AcceptAppointmentRequest::class)->handle(
                            request: $this->record,
                            reviewer: auth()->user(),
                            appointmentTypeId: $data['appointment_type_id'],
                        );

                        $this->record->refresh();
                        Notification::make()
                            ->title("Appointment {$appointment->appointment_number} created")
                            ->success()
                            ->send();
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot accept.';
                        Notification::make()->title('Cannot accept')->body($message)->danger()->send();
                    }
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
                ->action(function (array $data): void {
                    try {
                        app(RejectAppointmentRequest::class)->handle(
                            request: $this->record,
                            reviewer: auth()->user(),
                            reason: $data['reason'],
                        );

                        $this->record->refresh();
                        Notification::make()->title('Request rejected')->success()->send();
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot reject.';
                        Notification::make()->title('Cannot reject')->body($message)->danger()->send();
                    }
                }),
        ];
    }
}
