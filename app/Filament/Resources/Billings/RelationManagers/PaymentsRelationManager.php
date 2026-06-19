<?php

namespace App\Filament\Resources\Billings\RelationManagers;

use App\Actions\Billing\RecalculateBillingBalance;
use App\Models\PaymentStatus;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('paid_at')
                    ->label('Date')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                TextColumn::make('paymentMethod.name')
                    ->label('Method'),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->money('PHP'),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'posted' => 'success',
                        'voided' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('reference_number')
                    ->label('Reference')
                    ->placeholder('—'),
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
