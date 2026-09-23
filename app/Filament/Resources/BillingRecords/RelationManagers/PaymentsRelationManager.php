<?php

namespace App\Filament\Resources\BillingRecords\RelationManagers;

use App\Actions\BillingRecords\RecordBillingPayment;
use App\Enums\BillingRecordStatus;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('amount')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->label('Method')
                    ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                TextColumn::make('reference_number')
                    ->label('Reference #')
                    ->placeholder('—'),
                TextColumn::make('recordedBy.first_name')
                    ->weight('bold')
                    ->label('Recorded By')
                    ->state(fn (BillingPayment $record): string => $record->recordedBy?->full_name ?? '—')
                    ->placeholder('—'),
                TextColumn::make('recorded_at')
                    ->label('Recorded')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                TextColumn::make('notes')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reversed_at')
                    ->label('Corrected')
                    ->dateTime('M j, Y g:i A')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('recordPayment')
                    ->label('Record Payment')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->registerModalActions([
                        Action::make('confirmRecordPayment')
                            ->requiresConfirmation()
                            ->modalHeading('Confirm payment')
                            ->modalDescription(fn (array $mountedActions): string => $this->getPaymentConfirmationDescription($mountedActions))
                            ->modalSubmitActionLabel('Record payment')
                            ->modalCancelActionLabel('Go back')
                            ->color('success')
                            ->overlayParentActions()
                            ->cancelParentActions()
                            ->action(function (array $mountedActions): void {
                                $data = $this->getPendingPaymentData($mountedActions);

                                $this->finalizePayment($data);
                            }),
                    ])
                    ->visible(fn (): bool => ! $this->isAccessoryOrderRequest()
                        && Gate::allows('recordPayment', $this->getOwnerRecord())
                        && in_array($this->getOwnerRecord()->status, [
                            BillingRecordStatus::Unpaid,
                            BillingRecordStatus::PartiallyPaid,
                        ], true))
                    ->schema([
                        Placeholder::make('balance_due')
                            ->label('Balance Due')
                            ->content(fn (): string => '₱'.number_format((float) $this->getOwnerRecord()->balance_due, 2)),
                        Toggle::make('charges_reviewed')
                            ->label("I've reviewed the charges on this bill")
                            ->helperText('Recording a payment will finalize the charges.')
                            ->default(false)
                            ->rule('accepted')
                            ->required()
                            ->visible(fn (): bool => ! $this->hasPostedPayments()),
                        TextInput::make('amount')
                            ->label('Amount')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->prefix('₱')
                            ->extraInputAttributes(['class' => 'price-input'])
                            ->maxValue(fn (): float => (float) $this->getOwnerRecord()->balance_due)
                            ->default(fn (): float => (float) $this->getOwnerRecord()->balance_due),
                        Select::make('payment_method')
                            ->label('Method')
                            ->options([
                                'cash' => 'Cash',
                                'gcash' => 'GCash',
                                'bank_transfer' => 'Bank Transfer',
                            ])
                            ->default('cash')
                            ->required()
                            ->live(),
                        TextInput::make('reference_number')
                            ->label('Reference #')
                            ->visible(fn (Get $get): bool => $get('payment_method') !== 'cash')
                            ->nullable(),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->nullable(),
                    ])
                    ->modalSubmitActionLabel('Save changes')
                    ->action(function (): void {
                        $this->mountAction('confirmRecordPayment');
                    }),
            ])
            ->recordActions([
                $this->viewPaymentDetailsAction(),
            ])
            ->emptyStateHeading('No payments recorded')
            ->emptyStateDescription('Record the first payment when the patient makes one.')
            ->defaultSort('recorded_at', 'desc');
    }

    private function viewPaymentDetailsAction(): Action
    {
        return Action::make('viewCorrectionReason')
            ->label('View')
            ->icon(Heroicon::PencilSquare)
            ->link()
            ->modalHeading('Payment Details')
            ->modalDescription('Review the payment and correction details.')
            ->modalWidth('3xl')
            ->schema([
                Section::make('Payment Details')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('payment_id')
                                ->label('Payment #')
                                ->state(fn (BillingPayment $record): string => '#'.$record->getKey()),
                            TextEntry::make('amount')
                                ->label('Amount')
                                ->state(fn (BillingPayment $record): string => '₱'.number_format((float) $record->amount, 2)),
                            TextEntry::make('payment_method')
                                ->label('Method')
                                ->state(fn (BillingPayment $record): string => Str::headline($record->payment_method)),
                            TextEntry::make('reference_number')
                                ->label('Reference #')
                                ->state(fn (BillingPayment $record): string => $record->reference_number ?? '—'),
                            TextEntry::make('status')
                                ->badge()
                                ->state(fn (BillingPayment $record): string => $this->getPaymentStatusLabel($record->status))
                                ->color(fn (BillingPayment $record): string => match ($record->status) {
                                    'posted' => 'success',
                                    'reversed' => 'danger',
                                    default => 'gray',
                                }),
                            TextEntry::make('recorded_by')
                                ->label('Recorded By')
                                ->state(fn (BillingPayment $record): string => $record->recordedBy?->full_name ?? '—'),
                            TextEntry::make('recorded_at')
                                ->label('Recorded At')
                                ->state(fn (BillingPayment $record): string => $record->recorded_at?->format('M j, Y g:i A') ?? '—'),
                        ]),
                        TextEntry::make('notes')
                            ->label('Notes')
                            ->state(fn (BillingPayment $record): string => $record->notes ?? '—')
                            ->columnSpanFull(),
                    ]),
                Section::make('Correction Details')
                    ->schema([
                        Grid::make(2)->schema([
                            TextEntry::make('corrected_by')
                                ->label('Corrected By')
                                ->state(fn (BillingPayment $record): string => $record->reversedBy?->full_name ?? '—'),
                            TextEntry::make('corrected_at')
                                ->label('Corrected At')
                                ->state(fn (BillingPayment $record): string => $record->reversed_at?->format('M j, Y g:i A') ?? '—'),
                        ]),
                        TextEntry::make('correction_reason')
                            ->label('Correction Reason')
                            ->state(fn (BillingPayment $record): string => $record->reversal_reason ?? '—')
                            ->columnSpanFull(),
                    ]),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->visible(fn (BillingPayment $record): bool => filled($record->reversal_reason));
    }

    private function getPaymentStatusLabel(string $status): string
    {
        return $status === 'reversed' ? 'Corrected' : Str::headline($status);
    }

    /**
     * @param  array<int, Action>  $mountedActions
     */
    private function getPaymentConfirmationDescription(array $mountedActions): string
    {
        $data = $this->getPendingPaymentData($mountedActions);
        $amount = number_format((float) ($data['amount'] ?? 0), 2);

        return "Amount paid: ₱{$amount}. This will update the billing record's amount paid and balance due. Are you sure you want to record this payment?";
    }

    /**
     * Filament resets processed parent action data after opening the confirmation action.
     * The raw state retains the validated payment form values for the final step.
     *
     * @param  array<int, Action>  $mountedActions
     * @return array<string, mixed>
     */
    private function getPendingPaymentData(array $mountedActions): array
    {
        return ($mountedActions[0] ?? null)?->getRawData() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function finalizePayment(array $data): void
    {
        /** @var BillingRecord $billingRecord */
        $billingRecord = $this->getOwnerRecord();

        try {
            if ($this->isAccessoryOrderRequest()) {
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
                notes: $data['notes'] ?? null,
                chargesReviewed: (bool) ($data['charges_reviewed'] ?? false),
            );

            $billingRecord->refresh();
            $this->dispatch('billing-payment-updated');
            Notification::make()->title('Payment recorded')->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Cannot record payment')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    private function isAccessoryOrderRequest(): bool
    {
        return $this->getOwnerRecord()->jobOrder?->accessoryOrderRequest !== null;
    }

    private function hasPostedPayments(): bool
    {
        return $this->getOwnerRecord()
            ->payments()
            ->where('status', 'posted')
            ->exists();
    }
}
