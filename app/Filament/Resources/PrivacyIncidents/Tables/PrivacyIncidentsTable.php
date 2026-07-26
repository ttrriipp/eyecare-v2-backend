<?php

namespace App\Filament\Resources\PrivacyIncidents\Tables;

use App\Enums\IncidentStatus;
use App\Models\PrivacyIncident;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PrivacyIncidentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_number')
                    ->label('Reference #')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (PrivacyIncident $record): string => match ($record->status) {
                        IncidentStatus::Reported => 'danger',
                        IncidentStatus::UnderInvestigation => 'warning',
                        IncidentStatus::Contained => 'info',
                        IncidentStatus::Resolved => 'success',
                        IncidentStatus::Closed => 'gray',
                    }),
                TextColumn::make('reportedBy.name')
                    ->label('Reported By')
                    ->placeholder('—'),
                TextColumn::make('assignedTo.name')
                    ->label('Assigned To')
                    ->placeholder('—'),
                TextColumn::make('discovered_at')
                    ->label('Discovered')
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(IncidentStatus::class),
            ])
            ->recordActions([
                EditAction::make()->label('View'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
