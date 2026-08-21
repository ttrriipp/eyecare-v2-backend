<?php

namespace App\Filament\Resources\Appointments\Tables;

use App\Actions\Appointments\AssignAppointmentOptometrist;
use App\Actions\Appointments\CancelAppointment;
use App\Actions\Appointments\MarkAppointmentNoShow;
use App\Actions\Appointments\RescheduleAppointment;
use App\Actions\Encounters\CheckInAppointment;
use App\Actions\Encounters\StartEncounter;
use App\Enums\EncounterStatus;
use App\Filament\Resources\Appointments\Support\AppointmentTime;
use App\Filament\Resources\Encounters\EncounterResource;
use App\Models\Appointment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class AppointmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('appointment_number')
                    ->label('Number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('patient.full_name')
                    ->label('Patient')
                    ->searchable(['patient.first_name', 'patient.last_name'])
                    ->sortable(),
                TextColumn::make('appointmentType.name')
                    ->label('Appointment Type'),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (Appointment $record): string => match ($record->status?->name) {
                        'scheduled' => 'info',
                        'checked_in' => 'warning',
                        'fulfilled' => 'success',
                        'no_show' => 'gray',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                TextColumn::make('optometrist.first_name')
                    ->label('Optometrist')
                    ->placeholder('Unassigned')
                    ->state(fn (Appointment $record): string => $record->optometrist?->full_name ?? 'Unassigned')
                    ->searchable(['optometrist.first_name', 'optometrist.last_name'])
                    ->sortable(),
                TextColumn::make('createdBy.first_name')
                    ->label('Booked by')
                    ->placeholder('System / patient')
                    ->state(fn (Appointment $record): string => $record->createdBy?->full_name ?? 'System / patient')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'mobile', 'mobile_app' => 'Mobile app',
                        'walk_in' => 'Walk-in',
                        'manual' => 'Admin panel',
                        'phone_call' => 'Phone call',
                        'messenger' => 'Messenger',
                        default => Str::headline($state),
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('scheduled_at')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                TextColumn::make('contact_notes')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('walk_in_queue')
                    ->label("Today's walk-ins")
                    ->query(fn (Builder $query): Builder => $query
                        ->where('source', 'walk_in')
                        ->whereDate('scheduled_at', today())
                        ->whereHas('status', fn (Builder $statusQuery): Builder => $statusQuery->where('name', 'checked_in')))
                    ->toggle(),
                SelectFilter::make('optometrist')
                    ->relationship('optometrist', 'first_name', fn (Builder $query): Builder => $query->optometrists())
                    ->getOptionLabelFromRecordUsing(fn (User $user): string => $user->full_name)
                    ->searchable()
                    ->preload(),
                Filter::make('scheduled_date')
                    ->schema([
                        DatePicker::make('scheduled_on')
                            ->label('Scheduled date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['scheduled_on'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('scheduled_at', $date),
                        );
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->color('gray'),
                    Action::make('startConsultation')
                        ->label('Start Consultation')
                        ->icon('heroicon-o-play')
                        ->color('info')
                        ->visible(fn (Appointment $record): bool => $record->status?->name === 'checked_in'
                            && $record->encounter?->status === EncounterStatus::Planned
                            && auth()->user()?->isOptometrist() === true
                            && ($record->optometrist_id === null || $record->optometrist_id === auth()->id()))
                        ->requiresConfirmation()
                        ->modalHeading('Start Consultation')
                        ->modalDescription('You will become the treating optometrist for this consultation.')
                        ->action(function (Appointment $record, Component $livewire): void {
                            $encounter = $record->encounter;

                            if ($encounter === null) {
                                Notification::make()->title('No consultation found')->danger()->send();

                                return;
                            }

                            if ($encounter->status !== EncounterStatus::Planned) {
                                $livewire->redirect(EncounterResource::getUrl('edit', ['record' => $encounter]));

                                return;
                            }

                            try {
                                app(StartEncounter::class)->handle(
                                    encounter: $encounter,
                                    actor: auth()->user(),
                                );

                                Notification::make()->title('Consultation started')->success()->send();
                                $livewire->redirect(EncounterResource::getUrl('edit', ['record' => $encounter]));
                            } catch (ValidationException $e) {
                                $message = collect($e->errors())->flatten()->first() ?? 'Cannot start consultation.';
                                Notification::make()->title('Cannot start consultation')->body($message)->danger()->send();
                            }
                        }),
                    Action::make('viewEncounter')
                        ->label('View Consultation')
                        ->icon('heroicon-o-eye')
                        ->color('info')
                        ->visible(fn (Appointment $record): bool => in_array($record->encounter?->status, [
                            EncounterStatus::InProgress,
                            EncounterStatus::Completed,
                        ], true))
                        ->url(fn (Appointment $record): string => EncounterResource::getUrl('edit', [
                            'record' => $record->encounter,
                        ])),
                    Action::make('assign')
                        ->label('Assign')
                        ->icon('heroicon-o-user-plus')
                        ->color('info')
                        ->visible(fn (Appointment $record): bool => $record->status?->name === 'scheduled')
                        ->schema([
                            Select::make('optometrist_id')
                                ->label('Optometrist')
                                ->options(fn () => User::query()->optometrists()->orderBy('first_name')->orderBy('last_name')->get()->mapWithKeys(fn (User $user): array => [$user->id => $user->full_name]))
                                ->required()
                                ->searchable()
                                ->preload(),
                        ])
                        ->action(function (Appointment $record, array $data): void {
                            try {
                                $optometrist = User::findOrFail($data['optometrist_id']);
                                app(AssignAppointmentOptometrist::class)->handle($record, $optometrist);
                                Notification::make()->title('Optometrist assigned')->success()->send();
                            } catch (ValidationException $e) {
                                $message = collect($e->errors())->flatten()->first() ?? 'Cannot assign optometrist.';
                                Notification::make()->title('Cannot assign optometrist')->body($message)->danger()->send();
                            }
                        }),
                    Action::make('checkIn')
                        ->label('Check In')
                        ->icon('heroicon-o-arrow-right-start-on-rectangle')
                        ->color('warning')
                        ->visible(fn (Appointment $record): bool => $record->status?->name === 'scheduled')
                        ->requiresConfirmation()
                        ->action(function (Appointment $record): void {
                            try {
                                app(CheckInAppointment::class)->handle($record);
                                Notification::make()->title('Patient checked in — consultation created')->success()->send();
                            } catch (ValidationException $e) {
                                $message = collect($e->errors())->flatten()->first() ?? 'Cannot check in patient.';
                                Notification::make()->title('Cannot check in')->body($message)->danger()->send();
                            }
                        }),
                    Action::make('reschedule')
                        ->label('Reschedule')
                        ->icon('heroicon-o-calendar-days')
                        ->color('info')
                        ->visible(fn (Appointment $record): bool => $record->status?->name === 'scheduled')
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
                        ->action(function (Appointment $record, array $data): void {
                            try {
                                app(RescheduleAppointment::class)->handle(
                                    appointment: $record,
                                    scheduledAt: AppointmentTime::combine(
                                        $data['scheduled_at'],
                                        $data['appointment_time'],
                                    ),
                                    customerInitiated: false,
                                    rescheduleReason: $data['reschedule_reason'] ?? null,
                                    reasonCategory: $data['reason_category'],
                                );
                                Notification::make()->title('Appointment rescheduled')->success()->send();
                            } catch (ValidationException $e) {
                                $message = collect($e->errors())->flatten()->first() ?? 'Cannot reschedule appointment.';
                                Notification::make()->title('Cannot reschedule')->body($message)->danger()->send();
                            }
                        }),
                    Action::make('noShow')
                        ->label('Mark No-show')
                        ->icon('heroicon-o-user-minus')
                        ->color('warning')
                        ->visible(fn (Appointment $record): bool => $record->status?->name === 'scheduled' && $record->scheduled_at?->isPast())
                        ->requiresConfirmation()
                        ->action(function (Appointment $record): void {
                            try {
                                app(MarkAppointmentNoShow::class)->handle(
                                    appointment: $record,
                                    actor: auth()->user(),
                                );
                                Notification::make()->title('Appointment marked as no-show')->success()->send();
                            } catch (ValidationException $e) {
                                $message = collect($e->errors())->flatten()->first() ?? 'Cannot mark as no-show.';
                                Notification::make()->title('Cannot mark no-show')->body($message)->danger()->send();
                            }
                        }),
                    Action::make('cancel')
                        ->label('Cancel Appointment')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (Appointment $record): bool => in_array($record->status?->name, ['scheduled', 'checked_in'], true))
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
                        ->action(function (Appointment $record, array $data): void {
                            try {
                                app(CancelAppointment::class)->handle(
                                    appointment: $record,
                                    initiator: 'clinic',
                                    actor: auth()->user(),
                                    reasonCategory: $data['reason_category'],
                                    reasonDetails: $data['cancellation_details'] ?? null,
                                );
                                Notification::make()->title('Appointment cancelled')->success()->send();
                            } catch (ValidationException $e) {
                                $message = collect($e->errors())->flatten()->first() ?? 'Cannot cancel appointment.';
                                Notification::make()->title('Cannot cancel')->body($message)->danger()->send();
                            }
                        }),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_cancel')
                        ->label('Cancel Selected')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
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
                                ->maxLength(1000),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $cancelled = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if (! in_array($record->status?->name, ['scheduled', 'checked_in'], true)) {
                                    $skipped++;

                                    continue;
                                }

                                try {
                                    app(CancelAppointment::class)->handle(
                                        appointment: $record,
                                        initiator: 'clinic',
                                        actor: auth()->user(),
                                        reasonCategory: $data['reason_category'],
                                        reasonDetails: $data['cancellation_details'] ?? null,
                                    );
                                    $cancelled++;
                                } catch (\Throwable) {
                                    $skipped++;
                                }
                            }

                            Notification::make()
                                ->title("{$cancelled} appointment(s) cancelled".($skipped > 0 ? ", {$skipped} skipped" : ''))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('scheduled_at', 'desc');
    }
}
