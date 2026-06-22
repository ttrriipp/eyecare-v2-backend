<?php

namespace App\Filament\Resources\Billings\RelationManagers;

use App\Actions\Billing\RecalculateBillingBalance;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('amount')
                ->required()
                ->numeric()
                ->minValue(0.01)
                ->prefix('₱'),
            Select::make('payment_method_id')
                ->label('Method')
                ->required()
                ->options(fn () => PaymentMethod::query()->where('is_active', true)->pluck('name', 'id')),
            TextInput::make('reference_number')->maxLength(100),
            DateTimePicker::make('paid_at')->default(now()),
            Textarea::make('notes'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->searchable()
            ->columns([
                TextColumn::make('paid_at')
                    ->label('Date')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('paymentMethod.name')
                    ->label('Method'),
                TextColumn::make('reference_number')
                    ->label('Reference')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'posted' => 'success',
                        'voided' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->headerActions([
                Action::make('record_payment')
                    ->label('Record Payment')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (): bool => (float) $this->getOwnerRecord()->balance_due > 0
                        && $this->getOwnerRecord()->status->name !== 'voided')
                    ->schema([
                        TextInput::make('amount')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->maxValue(fn (): float => (float) $this->getOwnerRecord()->balance_due)
                            ->prefix('₱')
                            ->helperText(function (): ?string {
                                $billing = $this->getOwnerRecord();

                                if ($billing->payments()->whereHas('status', fn ($q) => $q->where('name', 'posted'))->exists()) {
                                    return null;
                                }

                                return 'Suggested downpayment (50%): ₱'.number_format((float) $billing->total_amount / 2, 2);
                            }),
                        Select::make('payment_method_id')
                            ->label('Method')
                            ->required()
                            ->options(fn () => PaymentMethod::query()->where('is_active', true)->pluck('name', 'id')),
                        TextInput::make('reference_number')->maxLength(100),
                        DateTimePicker::make('paid_at')->default(now()),
                        Textarea::make('notes'),
                    ])
                    ->action(function (array $data): void {
                        app(RecordPayment::class)->handle($this->getOwnerRecord(), $data);
                    })
                    ->successNotificationTitle('Payment recorded'),
            ])
            ->recordActions([
                Action::make('void')
                    ->label('Void')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn ($record): bool => $record->status->name === 'posted')
                    ->action(function ($record): void {
                        $voidedStatus = PaymentStatus::query()->where('name', 'voided')->firstOrFail();
                        $record->update(['payment_status_id' => $voidedStatus->id]);
                        app(RecalculateBillingBalance::class)->handle($this->getOwnerRecord());
                    })
                    ->successNotificationTitle('Payment voided'),
            ])
            ->defaultSort('paid_at', 'desc');
    }
}
