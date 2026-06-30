<?php

namespace App\Filament\Resources\Billings\Pages;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Billing\AddServiceToBilling;
use App\Actions\Billing\RecalculateBillingBalance;
use App\Actions\Billing\RecordPayment;
use App\Filament\Resources\Billings\BillingResource;
use App\Models\BillingStatus;
use App\Models\DiscountType;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Service;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
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
            Action::make('download_receipt')
                ->label('Download Receipt')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->tooltip('Download as A4 PDF')
                ->url(fn () => route('pdf.billing', $this->getRecord()))
                ->openUrlInNewTab(),

            Action::make('print_thermal')
                ->label('Print Receipt')
                ->icon('heroicon-o-printer')
                ->color('info')
                ->tooltip('Print on 80mm thermal receipt printer')
                ->url(fn () => route('thermal.billing', $this->getRecord()))
                ->openUrlInNewTab(),

            Action::make('record_payment_shortcut')
                ->label('Record Payment')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (): bool => (float) $this->getRecord()->balance_due > 0
                    && $this->getRecord()->status?->name !== 'voided')
                ->schema([
                    TextInput::make('amount')
                        ->label('Amount')
                        ->required()
                        ->numeric()
                        ->minValue(0.01)
                        ->prefix('₱')
                        ->live(onBlur: true)
                        ->default(fn () => (float) $this->getRecord()->balance_due),
                    Select::make('payment_method_id')
                        ->label('Payment Method')
                        ->required()
                        ->options(fn () => PaymentMethod::query()->where('is_active', true)->pluck('name', 'id'))
                        ->live(),
                    Placeholder::make('change_due')
                        ->label('Change')
                        ->content(function (Get $get): string {
                            $tendered = (float) ($get('cash_tendered') ?? 0);
                            $amount = (float) ($get('amount') ?? 0);
                            $change = $tendered - $amount;

                            return $change >= 0 ? '₱'.number_format($change, 2) : '—';
                        })
                        ->visible(fn (Get $get): bool => filled($get('cash_tendered'))),
                    TextInput::make('cash_tendered')
                        ->label('Cash Tendered')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('₱')
                        ->live(onBlur: true)
                        ->visible(fn (Get $get): bool => (int) $get('payment_method_id') === PaymentMethod::query()->where('name', 'Cash')->value('id'))
                        ->dehydrated(false),
                    TextInput::make('reference_number')->label('Reference Number')->maxLength(100)->nullable(),
                ])
                ->action(function (array $data): void {
                    app(RecordPayment::class)->handle($this->getRecord(), $data);
                    $this->refreshFormData(['billing_status_id', 'amount_paid', 'balance_due']);
                })
                ->successNotificationTitle('Payment recorded'),

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
                        ->options(fn () => DiscountType::query()
                            ->where('is_active', true)
                            ->get()
                            ->mapWithKeys(fn (DiscountType $dt) => [
                                $dt->id => $dt->type === 'percentage'
                                    ? "{$dt->name} ({$dt->value}%)"
                                    : $dt->name,
                            ]))
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
                ->modalDescription(function (): string {
                    $billing = $this->getRecord();
                    $postedPayments = $billing->payments()
                        ->whereHas('status', fn ($q) => $q->where('name', 'posted'))
                        ->sum('amount');

                    if ((float) $postedPayments > 0) {
                        return 'This billing has ₱'.number_format((float) $postedPayments, 2).' in posted payments. Voiding will mark those payments as voided and cannot be undone. The full billing state will be preserved in the audit log.';
                    }

                    return 'This will void the billing. This action is logged and cannot be undone.';
                })
                ->visible(fn (): bool => in_array($this->getRecord()->status->name, ['issued', 'partially_paid']) && (auth()->user()?->isAdmin() ?? false))
                ->action(function (): void {
                    $billing = $this->getRecord();

                    // Capture full state for audit before voiding
                    $payments = $billing->payments()
                        ->whereHas('status', fn ($q) => $q->where('name', 'posted'))
                        ->with('paymentMethod')
                        ->get();

                    $auditMetadata = [
                        'billing_number' => $billing->billing_number,
                        'total_amount' => (string) $billing->total_amount,
                        'amount_paid' => (string) $billing->amount_paid,
                        'balance_due' => (string) $billing->balance_due,
                        'payments_voided' => $payments->map(fn ($p) => [
                            'id' => $p->id,
                            'amount' => (string) $p->amount,
                            'method' => $p->paymentMethod?->name,
                            'paid_at' => $p->paid_at?->toDateTimeString(),
                        ])->all(),
                        'line_items' => $billing->items->map(fn ($item) => [
                            'description' => $item->description,
                            'quantity' => $item->quantity,
                            'unit_price' => (string) $item->unit_price,
                            'amount' => (string) $item->amount,
                        ])->all(),
                    ];

                    $voidedPaymentStatus = PaymentStatus::query()->where('name', 'voided')->firstOrFail();
                    $billing->payments()
                        ->whereHas('status', fn ($q) => $q->where('name', 'posted'))
                        ->update(['payment_status_id' => $voidedPaymentStatus->id]);

                    $voidedBillingStatus = BillingStatus::query()->where('name', 'voided')->firstOrFail();
                    $billing->update(['billing_status_id' => $voidedBillingStatus->id]);

                    app(CreateAuditLog::class)->handle($billing, 'voided', $auditMetadata);
                })
                ->successNotificationTitle('Billing voided'),
        ];
    }
}
