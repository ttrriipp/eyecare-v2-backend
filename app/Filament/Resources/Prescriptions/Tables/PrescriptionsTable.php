<?php

namespace App\Filament\Resources\Prescriptions\Tables;

use App\Models\Prescription;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PrescriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('patient.first_name')
                    ->label('Patient'),
                TextColumn::make('prescribed_at')
                    ->label('Date')
                    ->date('M j, Y')
                    ->sortable(),
                TextColumn::make('encounter.encounter_number')
                    ->label('Encounter')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('version_status')
                    ->label('Version')
                    ->state(fn (Prescription $record): string => $record->next_prescription_exists
                        ? 'Superseded'
                        : ($record->previous_prescription_id === null ? 'Original' : 'Current amendment'))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Superseded' ? 'warning' : 'success'),
                TextColumn::make('author.name')
                    ->label('Optometrist')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('prescribed_at', 'desc')
            ->filters([
                TrashedFilter::make()
                    ->label('Show Archived')
                    ->placeholder('Active only')
                    ->trueLabel('Active and archived')
                    ->falseLabel('Archived only'),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                ]),
            ]);
    }
}
