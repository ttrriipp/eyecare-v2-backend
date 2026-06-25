<?php

namespace App\Filament\Resources\Billings\Pages;

use App\Actions\Billing\AddServiceToBilling;
use App\Filament\Resources\Billings\BillingResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

class ViewBilling extends ViewRecord
{
    protected static string $resource = BillingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_order')
                ->label('View Order')
                ->icon('heroicon-o-shopping-bag')
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->order_id !== null)
                ->url(fn (): string => OrderResource::getUrl('edit', ['record' => $this->getRecord()->order_id])),

            Action::make('add_service')
                ->label('Add Service')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->visible(fn (): bool => $this->getRecord()->status->name !== 'voided')
                ->schema([
                    Select::make('service_id')
                        ->label('Service')
                        ->options(fn () => Service::query()->active()->pluck('name', 'id'))
                        ->required()
                        ->searchable(),
                    TextInput::make('amount')
                        ->label('Amount')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('₱')
                        ->helperText('Leave blank to use the service\'s default price.'),
                    Select::make('staff_id')
                        ->label('Performed by')
                        ->options(fn () => User::query()
                            ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'admin']))
                            ->pluck('name', 'id')
                        )
                        ->required()
                        ->default(fn () => auth()->id()),
                    DateTimePicker::make('performed_at')
                        ->label('Performed at')
                        ->required()
                        ->default(now()),
                ])
                ->action(function (array $data): void {
                    if (empty($data['amount'])) {
                        unset($data['amount']);
                    }
                    app(AddServiceToBilling::class)->handle($this->getRecord(), $data);
                })
                ->successNotificationTitle('Service added'),
        ];
    }
}
