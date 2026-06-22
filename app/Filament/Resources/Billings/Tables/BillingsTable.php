<?php

namespace App\Filament\Resources\Billings\Tables;

use App\Actions\Billing\RecordPayment;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Billing;
use App\Models\PaymentMethod;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BillingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('billing_number')
                    ->label('Billing #')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('order.customer.name')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'issued' => 'warning',
                        'partially_paid' => 'info',
                        'paid' => 'success',
                        'voided' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state))),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('balance_due')
                    ->label('Balance Due')
                    ->money('PHP')
                    ->sortable()
                    ->color(fn ($record): string => (float) $record->balance_due > 0 ? 'warning' : 'success'),
                TextColumn::make('order.created_at')
                    ->label('Order Date')
                    ->date('M j, Y')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('amount_paid')
                    ->label('Paid')
                    ->money('PHP')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('order.order_number')
                    ->label('Order #')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->relationship('status', 'name')
                    ->preload(),
                Filter::make('issued_from')
                    ->label('Issued from')
                    ->form([DatePicker::make('date')])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['date'],
                        fn (Builder $q, string $date) => $q->whereDate('issued_at', '>=', $date)
                    )),
                Filter::make('issued_until')
                    ->label('Issued until')
                    ->form([DatePicker::make('date')])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['date'],
                        fn (Builder $q, string $date) => $q->whereDate('issued_at', '<=', $date)
                    )),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    Action::make('view_order')
                        ->label('View Order')
                        ->icon('heroicon-o-shopping-bag')
                        ->color('gray')
                        ->url(fn ($record) => OrderResource::getUrl('edit', ['record' => $record->order_id])),
                    Action::make('record_payment')
                        ->label('Record Payment')
                        ->icon('heroicon-o-banknotes')
                        ->color('success')
                        ->visible(fn (Billing $record): bool => (float) $record->balance_due > 0
                            && $record->status->name !== 'voided')
                        ->schema([
                            TextInput::make('amount')
                                ->required()
                                ->numeric()
                                ->minValue(0.01)
                                ->maxValue(fn (Billing $record): float => (float) $record->balance_due)
                                ->prefix('₱'),
                            Select::make('payment_method_id')
                                ->label('Method')
                                ->required()
                                ->options(fn () => PaymentMethod::query()->where('is_active', true)->pluck('name', 'id')),
                            TextInput::make('reference_number')->maxLength(100),
                            DateTimePicker::make('paid_at')->default(now()),
                        ])
                        ->action(function (array $data, Billing $record): void {
                            app(RecordPayment::class)->handle($record, $data);
                        })
                        ->successNotificationTitle('Payment recorded'),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
