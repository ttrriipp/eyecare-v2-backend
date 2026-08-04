<?php

namespace App\Filament\Resources\Encounters\Tables;

use App\Enums\EncounterStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Models\Encounter;
use App\Models\Quotation;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
