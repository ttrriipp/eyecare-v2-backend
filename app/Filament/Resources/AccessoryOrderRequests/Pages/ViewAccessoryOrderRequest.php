<?php

namespace App\Filament\Resources\AccessoryOrderRequests\Pages;

use App\Actions\AccessoryOrderRequests\AcceptAccessoryOrderRequest;
use App\Actions\AccessoryOrderRequests\RejectAccessoryOrderRequest;
use App\Filament\Resources\AccessoryOrderRequests\AccessoryOrderRequestResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

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
                ->visible(fn (): bool => $this->record->isPending() && $this->canReviewCommerce())
                ->requiresConfirmation()
                ->modalHeading('Accept Order Request')
                ->modalDescription('This will create an Optical Order and Billing Record. Stock will be committed.')
                ->form([
                    TextInput::make('discount_amount')
                        ->label('Discount Amount')
                        ->numeric()
                        ->prefix('₱')
                        ->default(0)
                        ->minValue(0),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(AcceptAccessoryOrderRequest::class)->handle(
                            orderRequest: $this->record,
                            reviewer: auth()->user(),
                            discountAmount: (float) ($data['discount_amount'] ?? 0),
                        );

                        $this->record = $this->record->fresh();

                        Notification::make()
                            ->title('Request accepted')
                            ->success()
                            ->send();
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Cannot accept')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->isPending() && $this->canReviewCommerce())
                ->form([
                    Textarea::make('reason')
                        ->label('Rejection reason')
                        ->required()
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    try {
                        app(RejectAccessoryOrderRequest::class)->handle(
                            orderRequest: $this->record,
                            reviewer: auth()->user(),
                            reason: $data['reason'],
                        );
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Cannot reject')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->record = $this->record->fresh();

                    Notification::make()
                        ->title('Request rejected')
                        ->success()
                        ->send();
                }),
        ];
    }

    private function canReviewCommerce(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->is_active
            && ($user->isAdmin() || $user->isStaff());
    }
}
