<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AppointmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'appointments';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scheduled_at')->label('Date')->dateTime('M j, Y g:i A')->sortable(),
                TextColumn::make('visitReason.name')->label('Reason')->placeholder('—'),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (Appointment $record): string => match ($record->status?->name) {
                        'confirmed' => 'success',
                        'cancelled' => 'danger',
                        'completed' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => AppointmentResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('scheduled_at', 'desc')
            ->paginated(false);
    }
}
