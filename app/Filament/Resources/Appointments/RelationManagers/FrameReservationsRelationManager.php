<?php

namespace App\Filament\Resources\Appointments\RelationManagers;

use App\Enums\ReservationStatus;
use App\Filament\Resources\FrameReservations\FrameReservationResource;
use App\Models\FrameReservation;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (FrameReservation $record): string => match ($record->status) {
                        ReservationStatus::Requested => 'warning',
                        ReservationStatus::Prepared => 'info',
                        ReservationStatus::TriedOn => 'info',
                        ReservationStatus::Converted => 'success',
                        ReservationStatus::Released => 'gray',
                        ReservationStatus::Cancelled => 'danger',
                    })
                    ->formatStateUsing(fn (ReservationStatus $state): string => str($state->value)->headline()->toString()),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items'),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(ReservationStatus::class),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => FrameReservationResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated(false);
    }
}
