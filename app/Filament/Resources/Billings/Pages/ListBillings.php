<?php

namespace App\Filament\Resources\Billings\Pages;

use App\Actions\Billing\GenerateBillingForOrder;
use App\Filament\Resources\Billings\BillingResource;
use App\Models\Billing;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

class ListBillings extends ListRecords
{
    protected static string $resource = BillingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_billing')
                ->label('Generate Billing')
                ->schema([
                    Select::make('order_id')
                        ->label('Order')
                        ->options(
                            Order::query()
                                ->whereHas('status', fn ($q) => $q->where('name', 'confirmed'))
                                ->whereDoesntHave('billing')
                                ->get()
                                ->mapWithKeys(fn (Order $order): array => [
                                    $order->id => "#{$order->order_number} — {$order->customer->name}",
                                ])
                        )
                        ->searchable()
                        ->required()
                        ->rules([
                            fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                                $order = Order::query()->find($value);

                                if (! $order) {
                                    $fail('The selected order does not exist.');

                                    return;
                                }

                                if ($order->status->name !== 'confirmed') {
                                    $fail('Billing can only be generated for confirmed orders.');

                                    return;
                                }

                                if (Billing::query()->where('order_id', $value)->exists()) {
                                    $fail('A billing record already exists for this order.');
                                }
                            },
                        ]),
                ])
                ->action(function (array $data) {
                    $order = Order::query()->with('status')->findOrFail($data['order_id']);

                    try {
                        app(GenerateBillingForOrder::class)->handle($order);
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Could not generate billing')
                            ->body($e->errors()['order'][0] ?? 'An error occurred.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Billing generated successfully')
                        ->success()
                        ->send();
                }),
        ];
    }
}
