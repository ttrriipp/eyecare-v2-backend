<?php

namespace App\Filament\Resources\FrameReservations\Tables;

use App\Actions\Reservations\AcceptFrameReservation;
use App\Actions\Reservations\DeleteFrameReservation;
use App\Models\FrameReservation;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class FrameReservationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('patient.full_name')
                    ->label('Patient')
                    ->searchable(['patient.first_name', 'patient.last_name'])
                    ->sortable(),
                TextColumn::make('appointment.appointment_number')
                    ->label('Appointment')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('appointment.scheduled_at')
                    ->label('Scheduled')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                TextColumn::make('items_count')
                    ->label('Frames')
                    ->counts('items')
                    ->sortable(),
                TextColumn::make('held_state')
                    ->label('State')
                    ->state(fn (FrameReservation $record): string => $record->isHeld() ? 'Frames set aside' : 'Awaiting acceptance')
                    ->badge()
                    ->color(fn (FrameReservation $record): string => $record->isHeld() ? 'success' : 'warning'),
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('accept')
                        ->label('Accept & Set Aside')
                        ->icon('heroicon-o-check')
                        ->color('info')
                        ->visible(fn (FrameReservation $record): bool => ! $record->isHeld())
                        ->requiresConfirmation()
                        ->action(function (FrameReservation $record): void {
                            try {
                                app(AcceptFrameReservation::class)->handle($record);
                                Notification::make()->title('Reservation accepted — frames set aside')->success()->send();
                            } catch (ValidationException $e) {
                                Notification::make()->title('Cannot accept reservation')->body($e->getMessage())->danger()->send();
                            }
                        }),

                    Action::make('release')
                        ->label('Release Frames')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (FrameReservation $record): void {
                            app(DeleteFrameReservation::class)->handle($record);
                            Notification::make()->title('Reservation released')->success()->send();
                        }),

                    EditAction::make()->label('View'),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
