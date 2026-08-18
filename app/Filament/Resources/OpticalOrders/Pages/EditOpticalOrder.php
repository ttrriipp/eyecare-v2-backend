<?php

namespace App\Filament\Resources\OpticalOrders\Pages;

use App\Actions\BillingRecords\DispenseJobOrder;
use App\Actions\BillingRecords\RecordBillingPayment;
use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Actions\OpticalOrders\CancelOpticalOrder;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\BillingRecord;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class EditOpticalOrder extends EditRecord
{
    protected static string $resource = OpticalOrderResource::class;

    #[On('billing-payment-updated')]
    public function refreshBillingSummary(): void
    {
        $this->record->load(['activeBillingRecord', 'billingRecord']);
        $this->refreshFormData(['billing_status', 'billing_balance', 'billing_amount_paid', 'billing_due_date']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('start')
                ->label('Start Processing')
                ->icon('heroicon-o-play')
                ->color('warning')
                ->visible(fn (): bool => $this->record->status === JobOrderStatus::Queued)
                ->requiresConfirmation()
                ->modalHeading('Start Processing')
                ->modalDescription('Begin processing this optical order.')
                ->modalSubmitActionLabel('Start Processing')
                ->action(function (): void {
                    try {
                        app(UpdateJobOrderStatus::class)->handle($this->record, 'in_progress', auth()->user());
                        Notification::make()->title('Order started')->success()->send();
                        $this->refreshFormData(['status', 'started_at']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot start order')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('markReady')
                ->label('Mark Ready')
                ->icon('heroicon-o-check')
                ->color('info')
                ->visible(fn (): bool => $this->record->status === JobOrderStatus::InProgress)
                ->requiresConfirmation()
                ->modalHeading('Mark Ready for Pickup')
                ->modalDescription('Mark this order as ready for patient pickup.')
                ->modalSubmitActionLabel('Mark Ready')
                ->schema([
                    TextInput::make('supplier_invoice_number')
                        ->label('Supplier Invoice Number')
                        ->default(fn (): ?string => $this->record->supplier_invoice_number)
                        ->required(fn (): bool => $this->record->uses_external_supplier)
                        ->maxLength(100),
                ])
                ->action(function (array $data): void {
                    try {
                        DB::transaction(function () use ($data): void {
                            $this->record->update([
                                'supplier_invoice_number' => $data['supplier_invoice_number'],
                            ]);
                            app(UpdateJobOrderStatus::class)->handle($this->record, 'ready_for_dispensing', auth()->user());
                        });
                        Notification::make()->title('Order marked ready')->success()->send();
                        $this->refreshFormData(['status', 'supplier_invoice_number', 'ready_at']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot mark ready')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('cancel')
                ->label('Cancel')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => in_array($this->record->status, [JobOrderStatus::Queued, JobOrderStatus::InProgress], true))
                ->schema([
                    Textarea::make('cancellation_reason')
                        ->label('Reason')
                        ->nullable(),
                ])
                ->requiresConfirmation()
                ->modalDescription('This will cancel the order and reverse any committed inventory.')
                ->action(function (array $data): void {
                    try {
                        app(CancelOpticalOrder::class)->handle(
                            $this->record,
                            $data['cancellation_reason'] ?? null,
                            auth()->user(),
                        );

                        $this->record->refresh();
                        Notification::make()->title('Order cancelled')->success()->send();
                        $this->refreshFormData(['status', 'cancelled_at']);
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Cannot cancel order')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('viewQuotation')
                ->label('View Quotation')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn (): bool => $this->record->quotation !== null)
                ->url(fn (): string => QuotationResource::getUrl('edit', [
                    'record' => $this->record->quotation,
                ])),

            Action::make('viewBillingRecord')
                ->label('View Billing Record')
                ->icon('heroicon-o-banknotes')
                ->color('gray')
                ->visible(fn (): bool => $this->record->billingRecord !== null)
                ->url(fn (): string => BillingRecordResource::getUrl('edit', [
                    'record' => $this->activeBillingRecord() ?? $this->record->billingRecord,
                ])),

            Action::make('recordPayment')
                ->label('Record Payment')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (): bool => ($billingRecord = $this->activeBillingRecord()) !== null
                    && Gate::allows('recordPayment', $billingRecord)
                    && in_array($billingRecord->status, [
                        BillingRecordStatus::Unpaid,
                        BillingRecordStatus::PartiallyPaid,
                    ], true)
                    && (float) $billingRecord->balance_due > 0)
                ->schema([
                    Placeholder::make('balance_due')
                        ->label('Balance Due')
                        ->content(fn (): string => '₱'.number_format($this->outstandingBalance(), 2)),
                    Toggle::make('charges_reviewed')
                        ->label("I've reviewed the charges on this bill")
                        ->helperText('Check all charges before recording a payment.')
                        ->default(false)
                        ->rule('accepted')
                        ->required()
                        ->visible(fn (): bool => ! $this->activeBillingRecord()?->postedPayments()->exists()),
                    TextInput::make('amount')
                        ->label('Amount')
                        ->required()
                        ->numeric()
                        ->prefix('₱')
                        ->maxValue(fn (): float => $this->outstandingBalance())
                        ->default(fn (): float => $this->outstandingBalance()),
                    Select::make('payment_method')
                        ->label('Method')
                        ->options([
                            'cash' => 'Cash',
                            'gcash' => 'GCash',
                            'bank_transfer' => 'Bank Transfer',
                            'card' => 'Card',
                        ])
                        ->default('cash')
                        ->required(),
                    TextInput::make('reference_number')
                        ->label('Reference #')
                        ->nullable(),
                    Textarea::make('payment_notes')
                        ->label('Notes')
                        ->nullable(),
                ])
                ->action(function (array $data): void {
                    $billingRecord = $this->activeBillingRecord();

                    if ($billingRecord === null) {
                        return;
                    }

                    Gate::authorize('recordPayment', $billingRecord);

                    try {
                        app(RecordBillingPayment::class)->handle(
                            billingRecord: $billingRecord,
                            amount: (float) $data['amount'],
                            paymentMethod: $data['payment_method'],
                            recorder: auth()->user(),
                            referenceNumber: $data['reference_number'] ?? null,
                            notes: $data['payment_notes'] ?? null,
                            chargesReviewed: (bool) ($data['charges_reviewed'] ?? false),
                        );

                        $this->record->refresh();
                        $this->dispatch('billing-payment-updated');
                        Notification::make()->title('Payment recorded')->success()->send();
                        $this->refreshFormData(['billing_status', 'billing_balance', 'billing_amount_paid']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot record payment')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('dispense')
                ->label('Dispense')
                ->icon('heroicon-o-shopping-bag')
                ->color('success')
                ->visible(fn (): bool => $this->record->status === JobOrderStatus::ReadyForDispensing
                    && ($this->outstandingBalance() <= 0 || auth()->user()?->isAdmin() === true))
                ->requiresConfirmation()
                ->modalHeading('Dispense Order')
                ->modalDescription('Confirm the recipient, payment, and balance-release decision. Releasing with an outstanding balance requires an administrator, a reason, and a payment due date.')
                ->schema([
                    TextInput::make('recipient_name')
                        ->label('Recipient Name')
                        ->nullable()
                        ->maxLength(255),
                    Textarea::make('notes')
                        ->label('Notes')
                        ->nullable()
                        ->maxLength(1000),
                    TextInput::make('initial_payment_amount')
                        ->label('Initial Payment')
                        ->numeric()
                        ->prefix('₱')
                        ->nullable(),
                    Select::make('initial_payment_method')
                        ->label('Payment Method')
                        ->options([
                            'cash' => 'Cash',
                            'gcash' => 'GCash',
                            'bank_transfer' => 'Bank Transfer',
                            'card' => 'Card',
                        ])
                        ->nullable()
                        ->visible(fn (Get $get): bool => filled($get('initial_payment_amount'))),
                    Toggle::make('admin_override')
                        ->label('Release with outstanding balance')
                        ->helperText('Administrator-only exception. The order will be released while the remaining balance stays due.')
                        ->default(false)
                        ->live()
                        ->visible(fn (): bool => auth()->user()?->isAdmin() === true && $this->outstandingBalance() > 0),
                    Textarea::make('override_reason')
                        ->label('Balance Release Reason')
                        ->required(fn (Get $get): bool => (bool) $get('admin_override'))
                        ->visible(fn (Get $get): bool => (bool) $get('admin_override'))
                        ->maxLength(1000),
                    DatePicker::make('override_due_date')
                        ->label('Balance Payment Due Date')
                        ->required(fn (Get $get): bool => (bool) $get('admin_override'))
                        ->visible(fn (Get $get): bool => (bool) $get('admin_override'))
                        ->native(false)
                        ->minDate(today()),
                ])
                ->action(function (array $data): void {
                    try {
                        app(DispenseJobOrder::class)->handle(
                            jobOrder: $this->record,
                            dispenser: auth()->user(),
                            recipientName: $data['recipient_name'] ?? null,
                            notes: $data['notes'] ?? null,
                            pickupPaymentAmount: $data['initial_payment_amount'] ?? null,
                            pickupPaymentMethod: $data['initial_payment_method'] ?? null,
                            adminOverride: (bool) ($data['admin_override'] ?? false),
                            overrideReason: $data['override_reason'] ?? null,
                            overrideDueDate: $data['override_due_date'] ?? null,
                        );

                        Notification::make()->title('Order dispensed')->success()->send();
                        $this->refreshFormData(['status', 'dispensed_at']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot dispense')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    private function outstandingBalance(): float
    {
        $billingRecord = $this->activeBillingRecord();

        if ($billingRecord === null) {
            $balance = $this->record->billingRecord()
                ->where('status', '!=', 'voided')
                ->value('balance_due');

            return (float) ($balance ?? 0);
        }

        return (float) $billingRecord->balance_due;
    }

    private function activeBillingRecord(): ?BillingRecord
    {
        return $this->record->activeBillingRecord
            ?? $this->record->activeBillingRecord()->first();
    }
}
