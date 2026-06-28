<?php

namespace App\Filament\Resources\Billings\Pages;

use App\Actions\Billing\AddServiceToBilling;
use App\Actions\Billing\RecalculateBillingBalance;
use App\Filament\Resources\Billings\BillingResource;
use App\Models\BillingStatus;
use App\Models\DiscountType;
use App\Models\PaymentStatus;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;

class ViewBilling extends ViewRecord
{
    protected static string $resource = BillingResource::class;

    protected function getHeaderActions(): array
    {
        return [
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

            Action::make('apply_discount')
                ->label('Apply Discount')
                ->icon('heroicon-o-tag')
                ->color('warning')
                ->visible(fn (): bool => $this->getRecord()->status->name !== 'voided' && (auth()->user()?->isAdmin() ?? false))
                ->schema([
                    Select::make('discount_type_id')
                        ->label('Discount Type')
                        ->options(fn () => DiscountType::query()->where('is_active', true)->pluck('name', 'id'))
                        ->nullable()
                        ->live()
                        ->placeholder('No discount'),
                    TextInput::make('custom_amount')
                        ->label('Custom Amount (₱)')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('₱')
                        ->visible(fn (Get $get): bool => DiscountType::query()
                            ->find($get('discount_type_id'))?->type === 'fixed'
                        )
                        ->helperText('Enter fixed discount amount.'),
                ])
                ->action(function (array $data): void {
                    $billing = $this->getRecord();
                    $discountTypeId = $data['discount_type_id'] ?? null;
                    $discountAmount = '0.00';

                    if ($discountTypeId) {
                        $discountType = DiscountType::query()->findOrFail($discountTypeId);
                        $discountAmount = $discountType->type === 'percentage'
                            ? bcmul((string) $billing->subtotal, bcdiv((string) $discountType->value, '100', 4), 2)
                            : ($data['custom_amount'] ?? (string) $discountType->value);
                    }

                    $newTotal = bcsub((string) $billing->subtotal, (string) $discountAmount, 2);

                    $billing->update([
                        'discount_type_id' => $discountTypeId,
                        'discount_amount' => $discountAmount,
                        'total_amount' => $newTotal,
                        'balance_due' => bcsub((string) $newTotal, (string) $billing->amount_paid, 2),
                    ]);

                    app(RecalculateBillingBalance::class)->handle($billing->fresh());
                })
                ->successNotificationTitle('Discount applied'),

            Action::make('void_billing')
                ->label('Void Billing')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Void this billing?')
                ->modalDescription('This will void the billing and all posted payments. This cannot be undone.')
                ->visible(fn (): bool => in_array($this->getRecord()->status->name, ['issued', 'partially_paid']) && (auth()->user()?->isAdmin() ?? false))
                ->action(function (): void {
                    $billing = $this->getRecord();

                    $voidedPaymentStatus = PaymentStatus::query()->where('name', 'voided')->firstOrFail();
                    $billing->payments()
                        ->whereHas('status', fn ($q) => $q->where('name', 'posted'))
                        ->update(['payment_status_id' => $voidedPaymentStatus->id]);

                    $voidedBillingStatus = BillingStatus::query()->where('name', 'voided')->firstOrFail();
                    $billing->update(['billing_status_id' => $voidedBillingStatus->id]);
                })
                ->successNotificationTitle('Billing voided'),
        ];
    }
}
