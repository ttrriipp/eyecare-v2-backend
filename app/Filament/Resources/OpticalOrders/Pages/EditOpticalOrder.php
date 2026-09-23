<?php

namespace App\Filament\Resources\OpticalOrders\Pages;

use App\Actions\AccessoryOrderRequests\AcceptPaymentProof;
use App\Actions\AccessoryOrderRequests\RejectPaymentProof;
use App\Actions\BillingRecords\DispenseJobOrder;
use App\Actions\BillingRecords\RecordBillingPayment;
use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Actions\OpticalOrders\CancelOpticalOrder;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

class EditOpticalOrder extends EditRecord
{
    protected static string $resource = OpticalOrderResource::class;

    /**
     * @var array<string, string>
     */
    private const PAYMENT_PROOF_REJECTION_REASON_OPTIONS = [
        'unreadable' => 'The payment proof is blurry or unreadable.',
        'amount_mismatch' => 'The payment amount does not match the order total.',
        'reference_unverified' => 'The payment reference could not be verified.',
        'payment_not_received' => 'The payment could not be verified as received.',
        'other' => 'Other',
    ];

    public function getTitle(): string
    {
        $record = $this->getRecord();
        $patientName = $record->patient?->full_name ?? 'Unknown patient';

        return 'Optical Order for '.$patientName;
    }

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
                        $this->record->refresh();
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
                        $this->record->refresh();
                        Notification::make()->title('Order marked ready')->success()->send();
                        $this->refreshFormData(['status', 'supplier_invoice_number', 'ready_at']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot mark ready')->body($e->getMessage())->danger()->send();
                    }
                }),

            // Payment proof review actions (only for pending_payment/payment_review orders)
            Action::make('viewProof')
                ->label('View Payment Proof')
                ->icon('heroicon-o-document')
                ->color('info')
                ->visible(fn (): bool => $this->canReviewPaymentProof()
                    && in_array($this->record->status, [JobOrderStatus::PendingPayment, JobOrderStatus::PaymentReview], true)
                    && $this->record->paymentProof !== null)
                ->url(fn (): string => route('payment-proofs.download', ['proof' => $this->record->paymentProof]))
                ->openUrlInNewTab(),

            Action::make('acceptProof')
                ->label('Accept Payment')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->canReviewPaymentProof()
                    && $this->record->status === JobOrderStatus::PaymentReview
                    && $this->record->paymentProof?->isPending())
                ->requiresConfirmation()
                ->modalHeading('Accept Payment')
                ->modalDescription(fn (): string => 'This will record the full '.($this->record->paymentProof?->payment_method?->label() ?? 'online').' payment and queue the order for fulfillment.')
                ->action(function (): void {
                    try {
                        app(AcceptPaymentProof::class)->handle(
                            proof: $this->record->paymentProof,
                            reviewer: auth()->user(),
                        );
                        $this->record->refresh();
                        Notification::make()->title('Payment accepted')->success()->send();
                        $this->refreshFormData(['status']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot accept payment')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('rejectProof')
                ->label('Reject Payment')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->canReviewPaymentProof()
                    && $this->record->status === JobOrderStatus::PaymentReview
                    && $this->record->paymentProof?->isPending())
                ->form([
                    Select::make('reason_category')
                        ->label('Rejection reason')
                        ->options(self::PAYMENT_PROOF_REJECTION_REASON_OPTIONS)
                        ->required()
                        ->live()
                        ->helperText('Choose the closest match. Select Other to enter a custom reason.'),
                    Textarea::make('rejection_details')
                        ->label('Details')
                        ->visible(fn (Get $get): bool => $get('reason_category') === 'other')
                        ->required(fn (Get $get): bool => $get('reason_category') === 'other')
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    try {
                        $category = (string) ($data['reason_category'] ?? '');
                        $reason = $category === 'other'
                            ? trim((string) ($data['rejection_details'] ?? ''))
                            : self::PAYMENT_PROOF_REJECTION_REASON_OPTIONS[$category] ?? '';

                        app(RejectPaymentProof::class)->handle(
                            proof: $this->record->paymentProof,
                            reviewer: auth()->user(),
                            reason: $reason,
                        );
                        $this->record->refresh();
                        Notification::make()->title('Payment rejected, order cancelled')->success()->send();
                        $this->refreshFormData(['status']);
                    } catch (ValidationException $e) {
                        Notification::make()->title('Cannot reject payment')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('cancel')
                ->label('Cancel')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => in_array($this->record->status, [JobOrderStatus::Queued, JobOrderStatus::InProgress], true))
                ->schema([
                    Select::make('reason_category')
                        ->label('Cancellation Reason')
                        ->options([
                            'patient_request' => 'Patient request',
                            'product_unavailable' => 'Product unavailable',
                            'schedule_conflict' => 'Schedule conflict',
                            'duplicate' => 'Duplicate order',
                            'payment_issue' => 'Payment issue',
                            'other' => 'Other',
                        ])
                        ->required()
                        ->live(),
                    Textarea::make('cancellation_details')
                        ->label('Details')
                        ->required(fn (Get $get): bool => $get('reason_category') === 'other')
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ])
                ->requiresConfirmation()
                ->modalDescription('This will cancel the order and reverse any committed inventory.')
                ->action(function (array $data): void {
                    try {
                        $reason = ($data['reason_category'] ?? null) === 'other'
                            ? ($data['cancellation_details'] ?? null)
                            : Str::headline($data['reason_category'] ?? '');

                        app(CancelOpticalOrder::class)->handle(
                            $this->record,
                            $reason,
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
                ->color('info')
                ->visible(fn (): bool => $this->record->accessoryOrderRequest === null
                    && ($billingRecord = $this->activeBillingRecord()) !== null
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
                        ->helperText('Recording a payment will finalize the charges.')
                        ->default(false)
                        ->rule('accepted')
                        ->required()
                        ->visible(fn (): bool => ! $this->activeBillingRecord()?->postedPayments()->exists()),
                    TextInput::make('amount')
                        ->label('Amount')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->prefix('₱')
                        ->extraInputAttributes(['class' => 'price-input'])
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

                    try {
                        if ($this->record->accessoryOrderRequest !== null) {
                            throw ValidationException::withMessages([
                                'payment' => ['Payments for accessory order requests must be submitted through the patient app.'],
                            ]);
                        }

                        Gate::authorize('recordPayment', $billingRecord);

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
                ->schema([
                    Textarea::make('notes')
                        ->label('Notes')
                        ->nullable()
                        ->maxLength(1000),
                    Toggle::make('admin_override')
                        ->label('Release with outstanding balance')
                        ->helperText('Administrator-only exception. The order will be released while the remaining balance stays due.')
                        ->default(fn (): bool => $this->outstandingBalance() > 0)
                        ->live()
                        ->visible(fn (): bool => auth()->user()?->isAdmin() === true && $this->outstandingBalance() > 0),
                    Textarea::make('override_reason')
                        ->label('Reason for releasing before full payment')
                        ->required(fn (Get $get): bool => (bool) $get('admin_override'))
                        ->visible(fn (Get $get): bool => (bool) $get('admin_override'))
                        ->maxLength(1000),
                    DatePicker::make('override_due_date')
                        ->label('Payment Due Date')
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
                            notes: $data['notes'] ?? null,
                            adminOverride: (bool) ($data['admin_override'] ?? false),
                            overrideReason: $data['override_reason'] ?? null,
                            overrideDueDate: $data['override_due_date'] ?? null,
                        );

                        $this->record->refresh();
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
                ->where('status', '!=', 'cancelled')
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

    private function canReviewPaymentProof(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->is_active
            && ($user->isAdmin() || $user->isStaff());
    }
}
