<?php

namespace App\Filament\Resources\Billings\Pages;

use App\Actions\Billing\RecordPayment;
use App\Filament\Resources\Billings\BillingResource;
use App\Models\Billing;
use App\Models\PaymentMethod;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
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
                        ->prefix('₱')
                        ->helperText(function (): ?string {
                            $billing = $this->getRecord();

                            if ($billing->payments()->whereHas('status', fn ($q) => $q->where('name', 'posted'))->exists()) {
                                return null;
                            }

                            $half = number_format((float) $billing->total_amount / 2, 2);

                            return "Suggested downpayment (50%): ₱{$half}";
                        }),
                    Select::make('payment_method_id')
                        ->label('Method')
                        ->required()
                        ->options(fn () => PaymentMethod::query()->where('is_active', true)->pluck('name', 'id')),
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
        ];
    }
}
