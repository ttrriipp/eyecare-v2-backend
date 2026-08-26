<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Actions\Appointments\CancelAppointment;
use App\Actions\Appointments\LockAppointmentScheduleDate;
use App\Actions\Appointments\MarkAppointmentNoShow;
use App\Actions\Appointments\RescheduleAppointment;
use App\Actions\Appointments\ScheduleAppointment;
use App\Actions\Encounters\CheckInAppointment;
use App\Actions\Encounters\StartEncounter;
use App\Enums\EncounterStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Support\AppointmentTime;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditAppointment extends EditRecord
{
    protected static string $resource = AppointmentResource::class;

    /**
     * Revalidate schedule-defining edits through the same scheduling boundary
     * used by appointment creation and rescheduling.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Appointment || $record->status?->name !== 'scheduled') {
            return parent::handleRecordUpdate($record, $data);
        }

        $appointmentType = AppointmentType::query()->find($data['appointment_type_id'] ?? $record->appointment_type_id);

        if ($appointmentType === null || ! $appointmentType->is_active) {
            throw ValidationException::withMessages([
                'appointment_type_id' => ['The selected appointment type is inactive or unavailable.'],
            ]);
        }

        $durationMinutes = (int) ($data['duration_minutes'] ?? $record->duration_minutes);

        if ($appointmentType->requires_referral && blank($data['referring_source'] ?? $record->referring_source)) {
            throw ValidationException::withMessages([
                'referring_source' => ['Referring source is required for this appointment type.'],
            ]);
        }

        $optometrist = filled($data['optometrist_id'] ?? null)
            ? User::query()->findOrFail($data['optometrist_id'])
            : $record->optometrist;

        return DB::transaction(function () use ($record, $data, $durationMinutes, $optometrist): Model {
            app(LockAppointmentScheduleDate::class)->handle($record->scheduled_at);

            app(ScheduleAppointment::class)->handle(
                scheduledAt: $record->scheduled_at,
                durationMinutes: $durationMinutes,
                optometrist: $optometrist,
                ignoreAppointment: $record,
                enforceGrid: true,
            );

            return parent::handleRecordUpdate($record, $data);
        }, attempts: 3);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startConsultation')
                ->label('Start Consultation')
                ->icon('heroicon-o-play')
                ->color('info')
                ->visible(fn (): bool => $this->getRecord()->status?->name === 'checked_in'
                    && $this->getRecord()->encounter?->status === EncounterStatus::Planned
                    && auth()->user()->isOptometrist()
                    && (
                        $this->getRecord()->optometrist_id === null
                        || $this->getRecord()->optometrist_id === auth()->id()
                    ))
                ->requiresConfirmation()
                ->modalHeading('Start Consultation')
                ->modalDescription(fn (): string => $this->getRecord()->optometrist_id !== null
                    ? "Start consultation with {$this->getRecord()->optometrist?->full_name}?"
                    : 'You will be assigned as the optometrist for this consultation.')
                ->action(function (): void {
                    $encounter = $this->getRecord()->encounter;

                    if ($encounter === null) {
                        Notification::make()->title('No consultation found')->danger()->send();

                        return;
                    }

                    if ($encounter->status !== EncounterStatus::Planned) {
                        $this->redirect(EncounterResource::getUrl('edit', ['record' => $encounter]));

                        return;
                    }

                    try {
                        app(StartEncounter::class)->handle(
                            encounter: $encounter,
                            actor: auth()->user(),
                        );

                        Notification::make()->title('Consultation started')->success()->send();
                        $this->redirect(EncounterResource::getUrl('edit', ['record' => $encounter]));
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot start consultation.';
                        Notification::make()->title('Cannot start consultation')->body($message)->danger()->send();
                    }
                }),

            Action::make('viewEncounter')
                ->label('View Consultation')
                ->icon('heroicon-o-eye')
                ->color('info')
                ->visible(fn (): bool => in_array($this->getRecord()->encounter?->status, [
                    EncounterStatus::InProgress,
                    EncounterStatus::Completed,
                ], true))
                ->url(fn (): string => EncounterResource::getUrl('edit', [
                    'record' => $this->getRecord()->encounter,
                ])),

            Action::make('checkIn')
                ->label('Check In')
                ->icon('heroicon-o-arrow-right-start-on-rectangle')
                ->color('warning')
                ->visible(fn (): bool => $this->getRecord()->status?->name === 'scheduled')
                ->requiresConfirmation()
                ->modalHeading('Confirm Check-in')
                ->modalDescription('Patient will be checked in and a consultation will be created.')
                ->modalSubmitActionLabel('Check in')
                ->action(function (): void {
                    try {
                        app(CheckInAppointment::class)->handle($this->getRecord());
                        Notification::make()->title('Patient checked in — consultation created')->success()->send();
                        $this->redirect(EditAppointment::getUrl(['record' => $this->getRecord()]));
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot check in patient.';
                        Notification::make()->title('Cannot check in')->body($message)->danger()->send();
                    }
                }),

            Action::make('reschedule')
                ->label('Reschedule')
                ->icon('heroicon-o-calendar-days')
                ->color('info')
                ->visible(fn (): bool => $this->getRecord()->status?->name === 'scheduled')
                ->schema([
                    DatePicker::make('scheduled_at')
                        ->label('New appointment date')
                        ->required()
                        ->native(false)
                        ->displayFormat('M d, Y')
                        ->placeholder('Choose a new appointment date')
                        ->suffixIcon('heroicon-o-calendar-days')
                        ->minDate(today())
                        ->afterOrEqual('today'),
                    TimePicker::make('appointment_time')
                        ->label('New appointment time')
                        ->required()
                        ->seconds(false)
                        ->minutesStep(15)
                        ->format('H:i')
                        ->suffixIcon('heroicon-o-clock'),
                    Select::make('reason_category')
                        ->label('Reason')
                        ->options([
                            'patient_request' => 'Patient request',
                            'schedule_conflict' => 'Schedule conflict',
                            'provider_unavailable' => 'Provider unavailable',
                            'emergency' => 'Emergency',
                            'other' => 'Other',
                        ])
                        ->required()
                        ->live(),
                    Textarea::make('reschedule_reason')
                        ->label('Details')
                        ->required(fn (callable $get): bool => $get('reason_category') === 'other')
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    /** @var Appointment $appointment */
                    $appointment = $this->getRecord()->fresh(['status']);
                    try {
                        app(RescheduleAppointment::class)->handle(
                            appointment: $appointment,
                            scheduledAt: AppointmentTime::combine(
                                $data['scheduled_at'],
                                $data['appointment_time'],
                            ),
                            customerInitiated: false,
                            rescheduleReason: $data['reschedule_reason'] ?? null,
                            reasonCategory: $data['reason_category'],
                        );
                        Notification::make()->title('Appointment rescheduled')->success()->send();
                        $this->refreshFormData(['current_status', 'scheduled_at']);
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot reschedule appointment.';
                        Notification::make()->title('Cannot reschedule')->body($message)->danger()->send();
                    }
                }),

            Action::make('noShow')
                ->label('Mark No-show')
                ->icon('heroicon-o-user-minus')
                ->color('warning')
                ->visible(fn (): bool => $this->getRecord()->status?->name === 'scheduled' && $this->getRecord()->scheduled_at?->isPast())
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        app(MarkAppointmentNoShow::class)->handle(
                            appointment: $this->getRecord(),
                            actor: auth()->user(),
                        );
                        Notification::make()->title('Appointment marked as no-show')->success()->send();
                        $this->refreshFormData(['current_status']);
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot mark as no-show.';
                        Notification::make()->title('Cannot mark no-show')->body($message)->danger()->send();
                    }
                }),

            Action::make('cancel')
                ->label('Cancel Appointment')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => in_array($this->getRecord()->status?->name, ['scheduled', 'checked_in'], true))
                ->requiresConfirmation()
                ->schema([
                    Select::make('reason_category')
                        ->label('Cancellation Reason')
                        ->options([
                            'patient_request' => 'Patient request',
                            'schedule_conflict' => 'Schedule conflict',
                            'no_show_followup' => 'No-show follow-up',
                            'medical_reason' => 'Medical reason',
                            'duplicate' => 'Duplicate booking',
                            'other' => 'Other',
                        ])
                        ->required()
                        ->live(),
                    Textarea::make('cancellation_details')
                        ->label('Details')
                        ->required(fn (callable $get): bool => $get('reason_category') === 'other')
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(CancelAppointment::class)->handle(
                            appointment: $this->getRecord(),
                            initiator: 'clinic',
                            actor: auth()->user(),
                            reasonCategory: $data['reason_category'],
                            reasonDetails: $data['cancellation_details'] ?? null,
                        );
                        Notification::make()->title('Appointment cancelled')->success()->send();
                        $this->refreshFormData(['current_status']);
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot cancel appointment.';
                        Notification::make()->title('Cannot cancel')->body($message)->danger()->send();
                    }
                }),
        ];
    }
}
