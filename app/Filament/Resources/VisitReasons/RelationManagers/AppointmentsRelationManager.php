<?php

namespace App\Filament\Resources\VisitReasons\RelationManagers;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use Filament\Actions\Action;
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
                TextColumn::make('customer.name')->label('Patient')->searchable(),
                TextColumn::make('scheduled_at')->label('Date')->dateTime('M j, Y g:i A')->sortable(),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (Appointment $record): string => match ($record->status?->name) {
                        'pending' => 'gray',
                        'confirmed' => 'info',
                        'rescheduled' => 'warning',
                        'completed' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (Appointment $record): string => AppointmentResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('scheduled_at', 'desc')
            ->paginated(10);
    }
}
