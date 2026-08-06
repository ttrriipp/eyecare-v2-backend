<?php

namespace App\Filament\Resources\FrameReservations\Pages;

use App\Actions\Reservations\MarkFrameReservationTriedOn;
use App\Actions\Reservations\PrepareFrameReservation;
use App\Actions\Reservations\ReleaseFrameReservation;
use App\Enums\ReservationStatus;
use App\Filament\Resources\FrameReservations\FrameReservationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditFrameReservation extends EditRecord
{
    protected static string $resource = FrameReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prepare')
                ->label('Prepare')
                ->icon('heroicon-o-check')
                ->color('info')
                ->visible(fn (): bool => $this->record->status === ReservationStatus::Requested)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(PrepareFrameReservation::class)->handle($this->record);
                    $this->refreshFormData(['status']);
                }),

            Action::make('triedOn')
                ->label('Mark Tried On')
                ->icon('heroicon-o-eye')
                ->color('info')
                ->visible(fn (): bool => $this->record->status === ReservationStatus::Prepared)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(MarkFrameReservationTriedOn::class)->handle($this->record);
                    $this->refreshFormData(['status']);
                }),

            Action::make('release')
                ->label('Release')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (): bool => in_array($this->record->status, [ReservationStatus::Requested, ReservationStatus::Prepared, ReservationStatus::TriedOn], true))
                ->requiresConfirmation()
                ->action(function (): void {
                    app(ReleaseFrameReservation::class)->handle($this->record);
                    $this->refreshFormData(['status']);
                }),
        ];
    }
}
