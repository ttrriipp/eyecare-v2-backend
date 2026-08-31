<?php

namespace App\Filament\Resources\Encounters\Tables;

use App\Actions\Encounters\AssignEncounterOptometrist;
use App\Actions\Encounters\StartEncounter;
use App\Enums\EncounterStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\Quotation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class EncountersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('encounter_number')
                    ->label('Consultation #')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('patient.full_name')
                    ->label('Patient')
                    ->searchable(['patient.first_name', 'patient.last_name'])
                    ->sortable(),
                TextColumn::make('optometrist.first_name')
                    ->label('Optometrist')
                    ->placeholder('—')
                    ->state(fn (Encounter $record): string => $record->optometrist?->full_name ?? '—')
                    ->searchable(['optometrist.first_name', 'optometrist.last_name'])
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (Encounter $record): string => match ($record->status) {
                        EncounterStatus::Planned => 'gray',
                        EncounterStatus::InProgress => 'warning',
                        EncounterStatus::Completed => 'success',
                        EncounterStatus::Cancelled => 'danger',
                        EncounterStatus::Voided => 'danger',
                    })
                    ->formatStateUsing(fn (EncounterStatus $state): string => match ($state) {
                        EncounterStatus::Planned => 'Planned',
                        EncounterStatus::InProgress => 'In Progress',
                        EncounterStatus::Completed => 'Completed',
                        EncounterStatus::Cancelled => 'Cancelled',
                        EncounterStatus::Voided => 'Voided',
                    }),
                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime('M j, Y g:i A')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime('M j, Y g:i A')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(EncounterStatus::class),
                SelectFilter::make('optometrist')
                    ->relationship('optometrist', 'first_name', fn (Builder $query): Builder => $query->optometrists())
                    ->getOptionLabelFromRecordUsing(fn (User $user): string => $user->full_name)
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->label('View Consultation'),

                    Action::make('startEncounter')
                        ->label('Start Consultation')
                        ->icon('heroicon-o-play')
                        ->color('warning')
                        ->visible(fn (Encounter $record): bool => $record->status === EncounterStatus::Planned
                            && auth()->user()?->isOptometrist() === true
                            && ($record->optometrist_id === null || $record->optometrist_id === auth()->id()))
                        ->requiresConfirmation()
                        ->modalHeading('Start Consultation')
                        ->modalDescription('You will become the treating optometrist for this consultation.')
                        ->action(function (Encounter $record): void {
                            try {
                                app(StartEncounter::class)->handle(
                                    encounter: $record,
                                    actor: auth()->user(),
                                );

                                Notification::make()->title('Consultation started')->success()->send();
                            } catch (ValidationException $e) {
                                Notification::make()->title('Cannot start consultation')->body($e->getMessage())->danger()->send();
                            }
                        }),

                    Action::make('assignOptometrist')
                        ->label('Assign Optometrist')
                        ->icon('heroicon-o-user-plus')
                        ->color('info')
                        ->visible(fn (Encounter $record): bool => match (true) {
                            $record->status !== EncounterStatus::Planned => false,
                            default => auth()->user()->isAdmin()
                                || auth()->user()->isStaff()
                                || auth()->user()->isOptometrist(),
                        })
                        ->form([
                            Select::make('optometrist_id')
                                ->label('Optometrist')
                                ->options(fn () => User::query()->optometrists()->orderBy('first_name')->orderBy('last_name')->get()->mapWithKeys(fn (User $user): array => [$user->id => $user->full_name]))
                                ->required()
                                ->searchable()
                                ->preload(),
                        ])
                        ->action(function (Encounter $record, array $data): void {
                            try {
                                app(AssignEncounterOptometrist::class)->handle(
                                    encounter: $record,
                                    actor: auth()->user(),
                                    optometrist: User::query()->findOrFail($data['optometrist_id']),
                                );

                                Notification::make()->title('Optometrist assigned')->success()->send();
                            } catch (ValidationException $e) {
                                $message = collect($e->errors())->flatten()->first() ?? 'Cannot assign optometrist.';
                                Notification::make()->title('Cannot assign optometrist')->body($message)->danger()->send();
                            }
                        }),

                    Action::make('viewAppointment')
                        ->label('View Appointment')
                        ->icon('heroicon-o-calendar-days')
                        ->color('gray')
                        ->visible(fn (Encounter $record): bool => $record->appointment !== null)
                        ->url(fn (Encounter $record): string => AppointmentResource::getUrl('edit', [
                            'record' => $record->appointment,
                        ])),

                    Action::make('viewPrescription')
                        ->label('View Prescription')
                        ->icon('heroicon-o-eye')
                        ->color('gray')
                        ->visible(fn (Encounter $record): bool => $record->prescriptions()->exists())
                        ->url(fn (Encounter $record): string => PrescriptionResource::getUrl('view', [
                            'record' => $record->prescriptions()->latest('id')->value('id'),
                        ])),

                    Action::make('viewOpticalOrder')
                        ->label('View Optical Order')
                        ->icon('heroicon-o-shopping-bag')
                        ->color('gray')
                        ->visible(fn (Encounter $record): bool => Quotation::query()
                            ->where('encounter_id', $record->id)
                            ->exists())
                        ->url(function (Encounter $record): string {
                            $quotation = Quotation::query()
                                ->where('encounter_id', $record->id)
                                ->latest('id')
                                ->first();

                            if ($quotation?->jobOrder !== null) {
                                return OpticalOrderResource::getUrl('edit', ['record' => $quotation->jobOrder]);
                            }

                            return QuotationResource::getUrl('edit', ['record' => $quotation]);
                        }),
                ]),
            ])
            ->defaultSort(function (Builder $query): Builder {
                $encounterTable = $query->getModel()->getTable();
                $appointmentTable = (new Appointment)->getTable();
                $appointmentScheduledAt = Appointment::query()
                    ->select("{$appointmentTable}.scheduled_at")
                    ->whereColumn("{$appointmentTable}.id", "{$encounterTable}.appointment_id")
                    ->limit(1);

                return $query
                    ->addSelect([
                        'appointment_scheduled_at' => $appointmentScheduledAt,
                    ])
                    ->orderByRaw(
                        "CASE
                            WHEN {$encounterTable}.status = ? THEN 0
                            WHEN {$encounterTable}.status = ? THEN 1
                            WHEN {$encounterTable}.status IN (?, ?, ?) THEN 2
                            ELSE 3
                        END",
                        [
                            EncounterStatus::InProgress->value,
                            EncounterStatus::Planned->value,
                            EncounterStatus::Completed->value,
                            EncounterStatus::Cancelled->value,
                            EncounterStatus::Voided->value,
                        ],
                    )
                    ->orderByRaw(
                        "CASE
                            WHEN {$encounterTable}.status = ? AND {$encounterTable}.started_at IS NULL THEN 1
                            WHEN {$encounterTable}.status = ? AND appointment_scheduled_at IS NULL THEN 1
                            WHEN {$encounterTable}.status IN (?, ?, ?) AND {$encounterTable}.created_at IS NULL THEN 1
                            ELSE 0
                        END ASC",
                        [
                            EncounterStatus::InProgress->value,
                            EncounterStatus::Planned->value,
                            EncounterStatus::Completed->value,
                            EncounterStatus::Cancelled->value,
                            EncounterStatus::Voided->value,
                        ],
                    )
                    ->orderByRaw(
                        "CASE WHEN {$encounterTable}.status = ? THEN {$encounterTable}.started_at END ASC",
                        [EncounterStatus::InProgress->value],
                    )
                    ->orderByRaw(
                        "CASE WHEN {$encounterTable}.status = ? THEN appointment_scheduled_at END ASC",
                        [EncounterStatus::Planned->value],
                    )
                    ->orderByRaw(
                        "CASE WHEN {$encounterTable}.status IN (?, ?, ?) THEN {$encounterTable}.created_at END DESC",
                        [
                            EncounterStatus::Completed->value,
                            EncounterStatus::Cancelled->value,
                            EncounterStatus::Voided->value,
                        ],
                    )
                    ->orderBy("{$encounterTable}.id");
            });
    }
}
