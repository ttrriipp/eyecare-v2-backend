<?php

namespace App\Filament\Resources\AccessoryOrderRequests\Pages;

use App\Enums\AccessoryOrderRequestStatus;
use App\Filament\Resources\AccessoryOrderRequests\AccessoryOrderRequestResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewAccessoryOrderRequest extends ViewRecord
{
    protected static string $resource = AccessoryOrderRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('accept')
                ->label('Accept')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->isPending() && auth()->user()?->hasPanelRole())
                ->requiresConfirmation()
                ->modalHeading('Accept Order Request')
                ->modalDescription('This will create an Optical Order and Billing Record. Stock will be committed.')
                ->action(function (): void {
                    // Will be implemented in Task 6
                    Notification::make()
                        ->title('Acceptance action not yet implemented')
                        ->warning()
                        ->send();
                }),

            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->isPending() && auth()->user()?->hasPanelRole())
                ->form([
                    Textarea::make('reason')
                        ->label('Rejection reason')
                        ->required()
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    $this->record->update([
                        'status' => AccessoryOrderRequestStatus::Rejected,
                        'resolved_by' => auth()->id(),
                        'resolved_at' => now(),
                        'rejection_reason' => $data['reason'],
                    ]);

                    $this->record = $this->record->fresh();

                    Notification::make()
                        ->title('Request rejected')
                        ->success()
                        ->send();
                }),
        ];
    }
}
