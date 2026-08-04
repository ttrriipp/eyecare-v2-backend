<?php

namespace App\Filament\Resources\Encounters\Tables;

use App\Actions\Encounters\StartEncounter;
use App\Enums\EncounterStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
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
use Illuminate\Validation\ValidationException;

class EncountersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('encounter_number')
                    ->label('Encounter #')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('patient.first_name')
                    ->label('Patient'),
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
                    })
                    ->formatStateUsing(fn (EncounterStatus $state): string => match ($state) {
                        EncounterStatus::Planned => 'Planned',
                        EncounterStatus::InProgress => 'In Progress',
                        EncounterStatus::Completed => 'Completed',
                        EncounterStatus::Cancelled => 'Cancelled',
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
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->label('View Encounter'),

                    Action::make('startEncounter')
                        ->label('Start Visit')
                        ->icon('heroicon-o-play')
                        ->color('warning')
                        ->visible(fn (Encounter $record): bool => $record->status === EncounterStatus::Planned
                            && auth()->user()?->is_optometrist === true)
                        ->requiresConfirmation()
                        ->modalHeading('Start Visit')
                        ->modalDescription('Select the optometrist and start the visit.')
                        ->form([
                            Select::make('optometrist_id')
                                ->label('Optometrist')
                                ->options(fn () => User::query()->optometrists()->orderBy('first_name')->orderBy('last_name')->get()->mapWithKeys(fn (User $user): array => [$user->id => $user->full_name]))
                                ->default(auth()->id())
                                ->required()
                                ->searchable()
                                ->preload(),
                        ])
                        ->action(function (Encounter $record, array $data): void {
                            try {
                                $optometrist = User::query()->findOrFail($data['optometrist_id']);

                                app(StartEncounter::class)->handle(
                                    encounter: $record,
                                    optometrist: $optometrist,
                                    actor: auth()->user(),
                                );

                                Notification::make()->title('Encounter started')->success()->send();
                            } catch (ValidationException $e) {
                                Notification::make()->title('Cannot start encounter')->body($e->getMessage())->danger()->send();
                            }
                        }),

                    Action::make('assignOptometrist')
                        ->label('Assign Optometrist')
                        ->icon('heroicon-o-user-plus')
                        ->color('info')
                        ->visible(fn (Encounter $record): bool => $record->status === EncounterStatus::Planned)
                        ->form([
                            Select::make('optometrist_id')
                                ->label('Optometrist')
                                ->options(fn () => User::query()->optometrists()->orderBy('first_name')->orderBy('last_name')->get()->mapWithKeys(fn (User $user): array => [$user->id => $user->full_name]))
                                ->required()
                                ->searchable()
                                ->preload(),
                        ])
                        ->action(function (Encounter $record, array $data): void {
                            $record->update(['optometrist_id' => $data['optometrist_id']]);
                            Notification::make()->title('Optometrist assigned')->success()->send();
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
                        ->url(fn (Encounter $record): string => OpticalOrderResource::getUrl('view', [
                            'record' => Quotation::query()
                                ->where('encounter_id', $record->id)
                                ->latest('id')
                                ->value('id'),
                        ])),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
