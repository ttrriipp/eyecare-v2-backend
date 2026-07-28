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
                TextColumn::make('patient.full_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('prescribed_at')
                    ->date()
                    ->sortable(),
                TextColumn::make('version_status')
                    ->label('Version')
                    ->state(fn (Prescription $record): string => $record->next_prescription_exists
                        ? 'Superseded'
                        : ($record->previous_prescription_id === null ? 'Original' : 'Current amendment'))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Superseded' ? 'warning' : 'success'),
                TextColumn::make('expires_at')
                    ->date()
                    ->sortable(),
                TextColumn::make('pd')
                    ->label('PD')
                    ->sortable(),
                TextColumn::make('createdBy.name')
                    ->label('Recorded by')
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
