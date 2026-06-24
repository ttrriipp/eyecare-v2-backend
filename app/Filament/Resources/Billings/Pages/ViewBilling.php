<?php

namespace App\Filament\Resources\Billings\Pages;

use App\Actions\Billing\RecalculateBillingBalance;
use App\Actions\Billing\RecordPayment;
use App\Filament\Resources\Billings\BillingResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
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
            Action::make('view_source')
                ->label(fn () => $this->getRecord()->billable_type === Order::class ? 'View Order' : 'View Service Record')
                ->icon(fn () => $this->getRecord()->billable_type === Order::class ? 'heroicon-o-shopping-bag' : 'heroicon-o-clipboard-document-list')
                ->color('gray')
                ->url(function () {
                    $billing = $this->getRecord();

                    return $billing->billable_type === Order::class
                        ? OrderResource::getUrl('edit', ['record' => $billing->billable_id])
                        : ServiceRecordResource::getUrl('edit', ['record' => $billing->billable_id]);
                }),

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
                    $paymentId = $arguments['payment_id'] ?? null;

                    /** @var Payment $payment */
                    $payment = $this->getRecord()->payments()->findOrFail($paymentId);

                    $voidedStatus = PaymentStatus::query()->where('name', 'voided')->firstOrFail();
                    $payment->update(['payment_status_id' => $voidedStatus->id]);

                    app(RecalculateBillingBalance::class)->handle($this->getRecord());
                })
                ->successNotificationTitle('Payment voided'),
        ];
    }
}
