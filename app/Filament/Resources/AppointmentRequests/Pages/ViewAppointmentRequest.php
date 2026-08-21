<?php

namespace App\Filament\Resources\AppointmentRequests\Pages;

use App\Actions\Appointments\LinkAppointmentRequestToPatient;
use App\Actions\Appointments\RejectAppointmentRequest;
use App\Actions\PatientAccounts\RankPatientCandidates;
use App\Filament\Resources\AppointmentRequests\AppointmentRequestResource;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ViewAppointmentRequest extends ViewRecord
{
    protected static string $resource = AppointmentRequestResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            AppointmentRequestResource::getUrl('index') => 'Requests',
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
                ->visible(fn () => $this->record->isPending() && $this->record->patient_id === null)
                ->authorize('link')
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
                        ->whereNull('user_id')
                        ->whereNotIn('id', array_keys($candidateOptions))
                        ->get()
                        ->mapWithKeys(fn (Patient $p): array => [
                            $p->id => "{$p->full_name} ({$p->patient_number})",
                        ])
                        ->toArray();

                    $snapshot = $this->record->encrypted_identity_snapshot ?? [];

                    return [
                        ToggleButtons::make('patient_mode')
                            ->label('Patient')
                            ->options([
                                'existing' => 'Existing Patient',
                                'new' => 'New Patient',
                            ])
                            ->default('existing')
                            ->inline()
                            ->live()
                            ->required(),

                        Select::make('patient_id')
                            ->label('Clinical Record')
                            ->options(array_filter([
                                'Candidate Matches' => $candidateOptions,
                                'All Patients' => $otherOptions,
                            ]))
                            ->searchable()
                            ->required(fn (Get $get): bool => $get('patient_mode') === 'existing')
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'existing'),

                        TextInput::make('new_patient_first_name')
                            ->label('First Name')
                            ->default($snapshot['first_name'] ?? null)
                            ->required(fn (Get $get): bool => $get('patient_mode') === 'new')
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'new'),
                        TextInput::make('new_patient_middle_name')
                            ->label('Middle Name')
                            ->default($snapshot['middle_name'] ?? null)
                            ->nullable()
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'new'),
                        TextInput::make('new_patient_last_name')
                            ->label('Last Name')
                            ->default($snapshot['last_name'] ?? null)
                            ->required(fn (Get $get): bool => $get('patient_mode') === 'new')
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'new'),
                        TextInput::make('new_patient_phone')
                            ->label('Phone')
                            ->default($this->record->getSnapshotPhone())
                            ->required(fn (Get $get): bool => $get('patient_mode') === 'new')
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'new'),
                        TextInput::make('new_patient_contact_email')
                            ->label('Email')
                            ->email()
                            ->default($this->record->getSnapshotEmail())
                            ->nullable()
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'new'),
                        DatePicker::make('new_patient_date_of_birth')
                            ->label('Date of Birth')
                            ->default($this->record->getSnapshotDateOfBirth())
                            ->maxDate(now())
                            ->required(fn (Get $get): bool => $get('patient_mode') === 'new')
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'new'),
                        Select::make('new_patient_gender')
                            ->label('Gender')
                            ->options([
                                'male' => 'Male',
                                'female' => 'Female',
                                'other' => 'Other',
                            ])
                            ->default($this->record->getSnapshotGender())
                            ->nullable()
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'new'),
                        TextInput::make('new_patient_occupation')
                            ->label('Occupation')
                            ->default($this->record->getSnapshotOccupation())
                            ->nullable()
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'new'),
                        TextInput::make('new_patient_address')
                            ->label('Address')
                            ->default($this->record->getSnapshotAddress())
                            ->nullable()
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'new'),
                    ];
                })
                ->action(function (array $data): void {
                    try {
                        if (($data['patient_mode'] ?? 'existing') === 'new') {
                            $patient = Patient::create([
                                'first_name' => $data['new_patient_first_name'],
                                'middle_name' => $data['new_patient_middle_name'] ?? null,
                                'last_name' => $data['new_patient_last_name'],
                                'phone' => $data['new_patient_phone'] ?? null,
                                'contact_email' => $data['new_patient_contact_email'] ?? null,
                                'date_of_birth' => $data['new_patient_date_of_birth'] ?? null,
                                'gender' => $data['new_patient_gender'] ?? null,
                                'occupation' => $data['new_patient_occupation'] ?? null,
                                'address' => $data['new_patient_address'] ?? null,
                            ]);
                        } else {
                            $patient = Patient::findOrFail($data['patient_id']);
                        }

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

            Action::make('reviewSchedule')
                ->label('Review & Schedule')
                ->icon('heroicon-o-calendar-days')
                ->color('success')
                ->visible(fn (): bool => $this->record->isReadyForScheduleReview())
                ->authorize('accept')
                ->url(fn (): string => AppointmentRequestResource::getUrl('schedule', ['record' => $this->record])),

            Action::make('reject')
                ->label('Reject Request')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->isPending())
                ->authorize('reject')
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
