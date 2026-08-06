<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Actions\Appointments\CancelAppointment;
use App\Actions\Appointments\MarkAppointmentNoShow;
use App\Actions\Appointments\RescheduleAppointment;
use App\Actions\Encounters\CheckInAppointment;
use App\Actions\Encounters\StartEncounter;
use App\Actions\Reservations\CreateFrameReservation;
use App\Enums\EncounterStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Appointments\Support\AppointmentTime;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Filament\Resources\FrameReservations\FrameReservationResource;
use App\Models\Appointment;
use App\Models\FrameReservation;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditAppointment extends EditRecord
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startConsultation')
                ->label('Start Consultation')
                ->icon('heroicon-o-play')
                ->color('info')
                ->visible(fn (): bool => $this->getRecord()->status?->name === 'checked_in'
                    && $this->getRecord()->encounter?->status === EncounterStatus::Planned)
                ->requiresConfirmation()
                ->modalHeading('Start Consultation')
                ->modalDescription('Select the optometrist and start the consultation.')
                ->schema([
                    Select::make('optometrist_id')
                        ->label('Optometrist')
                        ->options(fn () => User::query()->optometrists()->orderBy('first_name')->orderBy('last_name')->get()->mapWithKeys(fn (User $user): array => [$user->id => $user->full_name])->toArray())
                        ->required()
                        ->searchable()
                        ->preload(),
                ])
                ->action(function (array $data): void {
                    $encounter = $this->getRecord()->encounter;

                    if ($encounter === null) {
                        Notification::make()->title('No encounter found')->danger()->send();

                        return;
                    }

                    if ($encounter->status !== EncounterStatus::Planned) {
                        $this->redirect(EncounterResource::getUrl('edit', ['record' => $encounter]));

                        return;
                    }

                    $optometristId = $data['optometrist_id'] ?? null;

                    if (empty($optometristId)) {
                        Notification::make()->title('No optometrist selected')->body('Please select an optometrist.')->danger()->send();

                        return;
                    }

                    $optometrist = User::findOrFail($optometristId);

                    try {
                        app(StartEncounter::class)->handle(
                            encounter: $encounter,
                            optometrist: $optometrist,
                            actor: auth()->user(),
                        );

                        Notification::make()->title('Consultation started')->success()->send();
                        $this->redirect(EncounterResource::getUrl('edit', ['record' => $encounter]));
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot start encounter.';
                        Notification::make()->title('Cannot start consultation')->body($message)->danger()->send();
                    }
                }),

            Action::make('viewEncounter')
                ->label('View Encounter')
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
                ->modalDescription('Patient will be checked in and an encounter will be created.')
                ->modalSubmitActionLabel('Check in')
                ->action(function (): void {
                    try {
                        app(CheckInAppointment::class)->handle($this->getRecord());
                        Notification::make()->title('Patient checked in — encounter created')->success()->send();
                        $this->redirect(EditAppointment::getUrl(['record' => $this->getRecord()]));
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot check in patient.';
                        Notification::make()->title('Cannot check in')->body($message)->danger()->send();
                    }
                }),

            Action::make('reserveFrames')
                ->label('Reserve Frames')
                ->icon('heroicon-o-eye')
                ->color('info')
                ->visible(fn (): bool => $this->getRecord()->status?->name === 'scheduled'
                    && ! $this->getRecord()->scheduled_at
                        ?->addMinutes($this->getRecord()->duration_minutes ?? 30)
                        ->isPast()
                    // A reservation is a before-the-visit tool — an appointment gets exactly
                    // one, ever, regardless of status (matches the DB-level unique constraint
                    // on appointment_id). Anything tried on in person after that flows
                    // straight into the sale without another reservation.
                    && ! FrameReservation::query()
                        ->where('appointment_id', $this->getRecord()->id)
                        ->exists())
                ->schema([
                    Select::make('product_variant_ids')
                        ->label('Frames')
                        ->options(fn (): array => ProductVariant::query()
                            ->active()
                            ->whereHas('product', fn ($q) => $q->where('is_active', true)->where('product_type', 'frame'))
                            ->with('product')
                            ->get()
                            ->mapWithKeys(fn (ProductVariant $variant): array => [
                                $variant->id => "{$variant->product->name} — {$variant->name} ({$variant->sku})",
                            ])
                            ->all())
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $appointment = $this->getRecord();

                    try {
                        $reservation = app(CreateFrameReservation::class)->handle(
                            patient: $appointment->patient,
                            appointment: $appointment,
                            items: collect($data['product_variant_ids'])
                                ->map(fn ($id): array => ['product_variant_id' => (int) $id])
                                ->all(),
                        );

                        Notification::make()
                            ->title('Frames reserved')
                            ->body("Reservation #{$reservation->id}")
                            ->success()
                            ->send();

                        $this->redirect(FrameReservationResource::getUrl('edit', ['record' => $reservation]));
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot reserve frames.';
                        Notification::make()->title('Cannot reserve frames')->body($message)->danger()->send();
                    }
                }),

            Action::make('viewReservation')
                ->label('View Reservation')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->visible(fn (): bool => FrameReservation::query()
                    ->where('appointment_id', $this->getRecord()->id)
                    ->exists())
                ->url(fn (): string => FrameReservationResource::getUrl('edit', [
                    'record' => FrameReservation::query()
                        ->where('appointment_id', $this->getRecord()->id)
                        ->firstOrFail(),
                ])),

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
                        ->minutesStep(1)
                        ->format('H:i'),
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
