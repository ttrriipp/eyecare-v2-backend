<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Models\Appointment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HealthRecordRelationManager extends RelationManager
{
    protected static string $relationship = 'appointments';

    protected static ?string $title = 'Health Record History';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (Appointment $record): string => $record->appointment_number)
            ->columns([
                TextColumn::make('scheduled_at')
                    ->label('Date')
                    ->dateTime('M d, Y g:i A')
                    ->sortable(),
                TextColumn::make('appointmentType.name')
                    ->label('Type'),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('intake.chief_complaint')
                    ->label('Chief Complaint')
                    ->limit(50)
                    ->placeholder('—'),
                TextColumn::make('intake.allergies')
                    ->label('Allergies')
                    ->limit(30)
                    ->placeholder('—'),
                TextColumn::make('optometrist.name')
                    ->label('Optometrist')
                    ->placeholder('—'),
            ])
            ->defaultSort('scheduled_at', 'desc')
            ->paginated([10]);
    }
}
