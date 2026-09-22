<?php

namespace App\Filament\Resources\AppointmentRequests\Pages;

use App\Actions\Appointments\LinkAppointmentRequestToPatient;
use App\Actions\Appointments\RejectAppointmentRequest;
use App\Actions\PatientAccounts\PatientAccountIdentityMatcher;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ViewAppointmentRequest extends ViewRecord
{
    protected static string $resource = AppointmentRequestResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();
        $patientName = $record->patient?->full_name ?? $record->getSnapshotDisplayName() ?? $record->user?->full_name ?? 'Unknown patient';

        return 'Appointment Request for '.$patientName;
    }

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
                        $matcher = app(PatientAccountIdentityMatcher::class);
                        $candidateOptions = app(RankPatientCandidates::class)
                            ->fromSnapshot($this->record->encrypted_identity_snapshot)
                            ->filter(fn (array $candidate): bool => $matcher
                                ->handleSnapshot($this->record->encrypted_identity_snapshot, $candidate['patient'])
                                ->isEligible())
                            ->mapWithKeys(fn (array $candidate): array => [
                                $candidate['patient']->id => "{$candidate['patient']->full_name} ({$candidate['patient']->patient_number}) — ".Str::headline($candidate['strength']).' match',
                            ])
                            ->toArray();
                    }

                    $snapshot = $this->record->encrypted_identity_snapshot ?? [];
                    $requester = $this->record->user;
                    $requesterPatient = $requester?->patient;
                    $defaults = [
                        'first_name' => $snapshot['first_name'] ?? $requester?->first_name ?? $requesterPatient?->first_name,
                        'middle_name' => $snapshot['middle_name'] ?? $requester?->middle_name ?? $requesterPatient?->middle_name,
                        'last_name' => $snapshot['last_name'] ?? $requester?->last_name ?? $requesterPatient?->last_name,
                        'phone' => $snapshot['phone'] ?? $requester?->phone ?? $requesterPatient?->phone,
                        'email' => $snapshot['email'] ?? $requester?->email ?? $requesterPatient?->contact_email,
                        'date_of_birth' => $snapshot['date_of_birth']
                            ?? $requester?->date_of_birth?->toDateString()
                            ?? $requesterPatient?->date_of_birth?->toDateString(),
                        'gender' => $snapshot['gender'] ?? $requesterPatient?->gender,
                        'occupation' => $snapshot['occupation'] ?? $requesterPatient?->occupation,
                        'address' => $snapshot['address'] ?? $requester?->address ?? $requesterPatient?->address,
                    ];

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
                            ]))
                            ->searchable()
                            ->required(fn (Get $get): bool => $get('patient_mode') === 'existing')
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'existing'),

                        TextInput::make('new_patient_first_name')
                            ->label('First Name')
                            ->default($defaults['first_name'])
                            ->required(fn (Get $get): bool => $get('patient_mode') === 'new')
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'new'),
                        TextInput::make('new_patient_middle_name')
                            ->label('Middle Name')
                            ->default($defaults['middle_name'])
                            ->nullable()
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'new'),
                        TextInput::make('new_patient_last_name')
                            ->label('Last Name')
                            ->default($defaults['last_name'])
                            ->required(fn (Get $get): bool => $get('patient_mode') === 'new')
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'new'),
                        TextInput::make('new_patient_phone')
                            ->label('Phone')
                            ->tel()
                            ->default($defaults['phone'])
                            ->prefix('+63')
                            ->formatStateUsing(fn (?string $state): ?string => $state !== null
                                ? preg_replace('/^\+63/', '', $state)
                                : null
                            )
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null
                                ? '+63'.preg_replace('/[^0-9]/', '', $state)
                                : null
                            )
                            ->required(fn (Get $get): bool => $get('patient_mode') === 'new')
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'new'),
                        TextInput::make('new_patient_contact_email')
                            ->label('Email')
                            ->email()
                            ->default($defaults['email'])
                            ->nullable()
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'new'),
                        DatePicker::make('new_patient_date_of_birth')
                            ->label('Date of Birth')
                            ->default($defaults['date_of_birth'])
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
                            ->default($defaults['gender'])
                            ->nullable()
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'new'),
                        TextInput::make('new_patient_occupation')
                            ->label('Occupation')
                            ->default($defaults['occupation'])
                            ->nullable()
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'new'),
                        TextInput::make('new_patient_address')
                            ->label('Address')
                            ->default($defaults['address'])
                            ->nullable()
                            ->visible(fn (Get $get): bool => $get('patient_mode') === 'new'),
                    ];
                })
                ->action(function (array $data): void {
                    try {
                        $patient = null;

                        DB::transaction(function () use ($data, &$patient): void {
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
                        });

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
                    Select::make('reason_category')
                        ->label('Rejection Reason')
                        ->options([
                            'patient_request' => 'Patient request',
                            'schedule_conflict' => 'Schedule conflict',
                            'provider_unavailable' => 'Provider unavailable',
                            'emergency' => 'Emergency',
                            'duplicate' => 'Duplicate request',
                            'other' => 'Other',
                        ])
                        ->required()
                        ->live(),
                    Textarea::make('rejection_details')
                        ->label('Details')
                        ->required(fn (Get $get): bool => $get('reason_category') === 'other')
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    try {
                        $reason = ($data['reason_category'] ?? null) === 'other'
                            ? ($data['rejection_details'] ?? null)
                            : Str::headline($data['reason_category'] ?? '');

                        app(RejectAppointmentRequest::class)->handle(
                            request: $this->record,
                            reviewer: auth()->user(),
                            reason: $reason,
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
