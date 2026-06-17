<?php

namespace App\Filament\Resources\Billings\Pages;

use App\Actions\Billing\RecalculateBillingBalance;
use App\Actions\Billing\RecordPayment;
use App\Filament\Resources\Billings\BillingResource;
use App\Models\Billing;
use App\Models\Payment;
use App\Models\PaymentStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

class ViewBilling extends ViewRecord
{
    protected static string $resource = BillingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('record_payment')
                ->label('Record Payment')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->hidden(fn () => $this->getRecord()->balance_due <= 0
                    || $this->getRecord()->status->name === 'voided')
                ->schema([
                    TextInput::make('amount')
                        ->required()
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(fn () => (float) $this->getRecord()->balance_due)
                        ->prefix('₱'),
                    TextInput::make('method')
                        ->required()
                        ->maxLength(50),
                    TextInput::make('reference_number')
                        ->maxLength(100),
                    DateTimePicker::make('paid_at')
                        ->default(now()),
                    Textarea::make('notes'),
                ])
                ->action(function (array $data): void {
                    /** @var Billing $billing */
                    $billing = $this->getRecord();
                    app(RecordPayment::class)->handle($billing, $data);
                })
                ->successNotificationTitle('Payment recorded'),

            Action::make('void_payment')
                ->label('Void Payment')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->arguments([
                    'payment_id' => null,
                ])
                ->action(function (array $arguments): void {
                    $payment = Payment::query()->findOrFail($arguments['payment_id']);

                    if ($payment->status->name !== 'posted') {
                        return;
                    }

                    $voidedStatus = PaymentStatus::query()->where('name', 'voided')->firstOrFail();
                    $payment->update(['payment_status_id' => $voidedStatus->id]);
                    app(RecalculateBillingBalance::class)->handle($this->getRecord());
                })
                ->successNotificationTitle('Payment voided'),
        ];
    }
}
