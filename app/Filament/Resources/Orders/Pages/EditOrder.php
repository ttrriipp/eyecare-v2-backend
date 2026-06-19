<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Orders\UpdateOrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirm')
                ->label('Confirm')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Confirm this order? Inventory will be deducted.')
                ->successNotificationTitle('Order confirmed')
                ->visible(fn (): bool => in_array($this->getRecord()->status->name, ['requested', 'under_review'], true))
                ->action(function (): void {
                    try {
                        app(UpdateOrderStatus::class)->handle($this->getRecord(), 'confirmed');
                    } catch (ValidationException $e) {
                        $message = collect($e->errors())->flatten()->first() ?? 'Cannot confirm order.';
                        Notification::make()->title('Cannot confirm order')->body($message)->danger()->send();
                    }
                }),

            Action::make('cancel')
                ->label('Cancel')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->successNotificationTitle('Order cancelled')
                ->visible(fn (): bool => ! in_array($this->getRecord()->status->name, ['completed', 'cancelled'], true))
                ->action(fn () => app(UpdateOrderStatus::class)->handle($this->getRecord(), 'cancelled')),

            RestoreAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}
