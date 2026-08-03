<?php

namespace App\Filament\Resources\BillingRecords\RelationManagers;

use App\Actions\BillingRecords\CorrectBillingPayment;
use App\Actions\BillingRecords\RecordBillingPayment;
use App\Enums\BillingRecordStatus;
use App\Models\BillingPayment;
use App\Models\BillingRecord;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                    ->visible(fn (): bool => Gate::allows('recordPayment', $this->getOwnerRecord())
                        && in_array($this->getOwnerRecord()->status, [
                            BillingRecordStatus::Unpaid,
                            BillingRecordStatus::PartiallyPaid,
                        ], true))
                    ->schema([
                        TextInput::make('amount')
                            ->label('Amount')
                            ->required()
                            ->numeric()
                            ->prefix('₱'),
                        Select::make('payment_method')
                            ->label('Method')
                            ->options([
                                'cash' => 'Cash',
                                'gcash' => 'GCash',
                                'bank_transfer' => 'Bank Transfer',
                                'card' => 'Card',
                            ])
                            ->required(),
                        TextInput::make('reference_number')
                            ->label('Reference #')
                            ->nullable(),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->nullable(),
                    ])
                    ->action(function (array $data): void {
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
                    }),
            ])
            ->recordActions([
                Action::make('correctPayment')
                    ->label('Correct Payment')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->visible(fn (BillingPayment $record): bool => $record->status === 'posted'
                        && Gate::allows('correctPayment', $this->getOwnerRecord()))
                    ->schema([
                        TextInput::make('new_amount')
                            ->label('Corrected Amount')
                            ->required()
                            ->numeric()
                            ->prefix('₱'),
                        TextInput::make('reference_number')
                            ->label('New Reference #')
                            ->nullable(),
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->fillForm(fn (BillingPayment $record): array => [
                        'new_amount' => $record->amount,
                        'reference_number' => $record->reference_number,
                    ])
                    ->action(function (array $data, BillingPayment $record): void {
                        /** @var BillingRecord $billingRecord */
                        $billingRecord = $this->getOwnerRecord();

                        Gate::authorize('correctPayment', $billingRecord);

                        try {
                            app(CorrectBillingPayment::class)->handle(
                                originalPayment: $record,
                                newAmount: (float) $data['new_amount'],
                                reason: $data['reason'],
                                corrector: auth()->user(),
                                newReferenceNumber: $data['reference_number'] ?? null,
                            );

                            $billingRecord->refresh();
                            $this->dispatch('billing-payment-updated');
                            Notification::make()->title('Payment corrected')->success()->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Cannot correct payment')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->emptyStateHeading('No payments recorded')
            ->emptyStateDescription('Record the first payment when the patient makes one.')
            ->defaultSort('recorded_at', 'desc');
    }
}
