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
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Carbon;
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
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'existing')
                            ->helperText('Select which clinical record this request belongs to.'),

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
                            ->nullable()
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
                            ->nullable()
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

            Action::make('accept')
                ->label('Accept Request')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->status === AppointmentRequestStatus::Pending && $this->record->patient_id !== null)
                ->schema(function (): array {
                    $defaultType = $this->record->appointmentType ?? AppointmentType::where('name', 'New Patient')->first();

                    return [
                        Select::make('appointment_type_id')
                            ->label('Appointment Type')
                            ->options(AppointmentType::active()->pluck('name', 'id'))
                            ->default($defaultType?->id)
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                $type = AppointmentType::find($state);
                                if ($type) {
                                    $set('duration_minutes', $type->duration_minutes);
                                }
                            }),

                        TextInput::make('duration_minutes')
                            ->label('Duration (minutes)')
                            ->numeric()
                            ->default($defaultType?->duration_minutes ?? 30)
                            ->minValue(5)
                            ->maxValue(240)
                            ->step(5)
                            ->required(),

                        Select::make('optometrist_id')
                            ->label('Optometrist')
                            ->options(User::query()->optometrists()->pluck('full_name', 'id'))
                            ->default($this->record->preferredOptometrist?->id)
                            ->required()
                            ->searchable(),

                        DateTimePicker::make('scheduled_at')
                            ->label('Final Date/Time')
                            ->default($this->record->scheduled_at)
                            ->required()
                            ->seconds(false),

                        TextInput::make('referring_source')
                            ->label('Referring Source')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => AppointmentType::find($get('appointment_type_id'))?->requires_referral ?? false)
                            ->required(fn (Get $get): bool => AppointmentType::find($get('appointment_type_id'))?->requires_referral ?? false),

                        Textarea::make('contact_notes')
                            ->label('Contact Note')
                            ->helperText('Required if the final time differs from all submitted preferences.')
                            ->rows(2),
                    ];
                })
                ->action(function (array $data): void {
                    try {
                        $appointmentType = AppointmentType::findOrFail($data['appointment_type_id']);
                        $optometrist = User::findOrFail($data['optometrist_id']);

                        $appointment = app(AcceptAppointmentRequest::class)->handle(
                            request: $this->record,
                            reviewer: auth()->user(),
                            appointmentType: $appointmentType,
                            durationMinutes: (int) $data['duration_minutes'],
                            scheduledAt: Carbon::parse($data['scheduled_at']),
                            optometrist: $optometrist,
                            referringSource: $data['referring_source'] ?? null,
                            contactNote: $data['contact_notes'] ?? null,
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
