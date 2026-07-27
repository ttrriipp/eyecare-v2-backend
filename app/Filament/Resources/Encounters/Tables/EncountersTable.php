<?php

namespace App\Filament\Resources\Encounters\Tables;

use App\Enums\EncounterStatus;
use App\Models\Encounter;
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
                TextColumn::make('patient.full_name')
                    ->label('Patient')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('optometrist.name')
                    ->label('Optometrist')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (Encounter $record): string => match ($record->status) {
                        EncounterStatus::Planned, EncounterStatus::Waiting => 'gray',
                        EncounterStatus::InProgress => 'warning',
                        EncounterStatus::Completed => 'success',
                        EncounterStatus::Cancelled => 'danger',
                    })
                    ->formatStateUsing(fn (EncounterStatus $state): string => match ($state) {
                        EncounterStatus::Planned, EncounterStatus::Waiting => 'Planned',
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
                EditAction::make()->label('View'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
