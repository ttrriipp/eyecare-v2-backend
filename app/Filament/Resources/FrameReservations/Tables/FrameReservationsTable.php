<?php

namespace App\Filament\Resources\FrameReservations\Tables;

use App\Actions\Reservations\PrepareFrameReservation;
use App\Actions\Reservations\ReleaseFrameReservation;
use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FrameReservationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('patient.first_name')
                    ->label('Patient'),
                TextColumn::make('appointment.appointment_number')
                    ->label('Appointment')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('appointment.scheduled_at')
                    ->label('Scheduled')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (FrameReservation $record): string => match ($record->status) {
                        ReservationStatus::Requested => 'gray',
                        ReservationStatus::Prepared => 'info',
                        ReservationStatus::TriedOn => 'warning',
                        ReservationStatus::Converted => 'success',
                        ReservationStatus::Released => 'danger',
                        ReservationStatus::Cancelled => 'danger',
                    })
                    ->formatStateUsing(fn (ReservationStatus $state): string => match ($state) {
                        ReservationStatus::Requested => 'Requested',
                        ReservationStatus::Prepared => 'Prepared',
                        ReservationStatus::TriedOn => 'Tried On',
                        ReservationStatus::Converted => 'Converted',
                        ReservationStatus::Released => 'Released',
                        ReservationStatus::Cancelled => 'Cancelled',
                    }),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ReservationStatus::class),
            ])
            ->recordActions([
                Action::make('prepare')
                    ->label('Prepare')
                    ->icon('heroicon-o-check')
                    ->color('info')
                    ->visible(fn (FrameReservation $record): bool => $record->status === ReservationStatus::Requested)
                    ->requiresConfirmation()
                    ->action(function (FrameReservation $record): void {
                        app(PrepareFrameReservation::class)->handle($record);
                    }),

                Action::make('release')
                    ->label('Release')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (FrameReservation $record): bool => in_array($record->status, [ReservationStatus::Requested, ReservationStatus::Prepared], true))
                    ->requiresConfirmation()
                    ->action(function (FrameReservation $record): void {
                        app(ReleaseFrameReservation::class)->handle($record);
                    }),

                EditAction::make()->label('View'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
