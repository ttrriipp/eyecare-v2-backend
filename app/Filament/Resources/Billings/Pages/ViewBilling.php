<?php

namespace App\Filament\Resources\Billings\Pages;

use App\Actions\Billing\AddServiceToBilling;
use App\Actions\Billing\RecalculateBillingBalance;
use App\Actions\Billing\RecordPayment;
use App\Filament\Resources\Billings\BillingResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
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

            Action::make('record_payment')
                ->label('Record Payment')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (): bool => (float) $this->getRecord()->balance_due > 0
                    && $this->getRecord()->status->name !== 'voided')
                ->schema([
                    TextInput::make('amount')
                        ->required()
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(fn (): float => (float) $this->getRecord()->balance_due)
                        ->prefix('₱'),
                    Select::make('payment_method_id')
                        ->label('Method')
                        ->required()
                        ->options(fn () => PaymentMethod::query()->where('is_active', true)->pluck('name', 'id')),
                    TextInput::make('reference_number')->maxLength(100),
                    DateTimePicker::make('paid_at')->default(now()),
                ])
                ->action(function (array $data): void {
                    app(RecordPayment::class)->handle($this->getRecord(), $data);
                })
                ->successNotificationTitle('Payment recorded'),

            Action::make('void_payment')
                ->label('Void Payment')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getRecord()->status->name !== 'voided')
                ->arguments(['payment_id' => null])
                ->action(function (array $arguments): void {
                    /** @var Payment $payment */
                    $payment = $this->getRecord()->payments()->findOrFail($arguments['payment_id'] ?? null);

                    $voidedStatus = PaymentStatus::query()->where('name', 'voided')->firstOrFail();
                    $payment->update(['payment_status_id' => $voidedStatus->id]);

                    app(RecalculateBillingBalance::class)->handle($this->getRecord());
                })
                ->successNotificationTitle('Payment voided'),
        ];
    }
}
