<?php

namespace App\Filament\Clusters\Availability\Resources\AppointmentTypes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AppointmentTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->weight('bold')
                    ->label('Internal Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('patient_label')
                    ->label('Patient Label')
                    ->searchable(),

                TextColumn::make('duration_minutes')
                    ->label('Duration')
                    ->sortable()
                    ->suffix(' min'),

                IconColumn::make('requires_referral')
                    ->label('Referral')
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                IconColumn::make('is_patient_visible')
                    ->label('Patient Visible')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only')
                    ->placeholder('All'),

                TernaryFilter::make('is_patient_visible')
                    ->label('Patient Visible')
                    ->boolean()
                    ->trueLabel('Visible only')
                    ->falseLabel('Internal only')
                    ->placeholder('All'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
