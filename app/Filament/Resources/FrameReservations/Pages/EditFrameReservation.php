<?php

namespace App\Filament\Resources\FrameReservations\Pages;

use App\Actions\Reservations\AcceptFrameReservation;
use App\Actions\Reservations\DeleteFrameReservation;
use App\Filament\Resources\FrameReservations\FrameReservationResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditFrameReservation extends EditRecord
{
    protected static string $resource = FrameReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('accept')
                ->label('Accept & Set Aside')
                ->icon('heroicon-o-check')
                ->color('info')
                ->visible(fn (): bool => ! $this->record->isHeld())
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        app(AcceptFrameReservation::class)->handle($this->record);
                        $this->refreshFormData(['accepted_at']);
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
                ->action(function (): void {
                    app(DeleteFrameReservation::class)->handle($this->record);
                    $this->redirectToList();
                }),
        ];
    }
}
