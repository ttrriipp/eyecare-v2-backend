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
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
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
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->color(fn (string $state): string => match ($state) {
                        'posted' => 'success',
                        'reversed' => 'danger',
                        default => 'gray',
                    }),
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
                TextColumn::make('reversal_reason')
                    ->label('Correction Reason')
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
                    ->visible(fn (): bool => Gate::allows('recordPayment', $this->getOwnerRecord())
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
                                'card' => 'Card',
                            ])
                            ->default('cash')
                            ->required(),
                        TextInput::make('reference_number')
                            ->label('Reference #')
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
            ->recordActions([])
            ->emptyStateHeading('No payments recorded')
            ->emptyStateDescription('Record the first payment when the patient makes one.')
            ->defaultSort('recorded_at', 'desc');
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

        Gate::authorize('recordPayment', $billingRecord);

        try {
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

    private function hasPostedPayments(): bool
    {
        return $this->getOwnerRecord()
            ->payments()
            ->where('status', 'posted')
            ->exists();
    }
}
