<?php

namespace App\Filament\Resources\Appointments\RelationManagers;

use App\Filament\Resources\FrameReservations\FrameReservationResource;
use App\Models\FrameReservation;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FrameReservationsRelationManager extends RelationManager
{
    protected static string $relationship = 'frameReservations';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Reservation #')
                    ->sortable(),
                TextColumn::make('patient.full_name')
                    ->label('Patient')
                    ->sortable(),
                TextColumn::make('held_state')
                    ->label('State')
                    ->badge()
                    ->state(fn (FrameReservation $record): string => $record->isHeld() ? 'Frames set aside' : 'Awaiting acceptance')
                    ->color(fn (FrameReservation $record): string => $record->isHeld() ? 'success' : 'warning'),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => FrameReservationResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated(false);
    }
}
