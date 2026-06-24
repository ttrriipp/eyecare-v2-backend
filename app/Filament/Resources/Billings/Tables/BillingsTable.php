<?php

namespace App\Filament\Resources\Billings\Tables;

use App\Actions\Billing\RecordPayment;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use App\Models\Billing;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\ServiceRecord;
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
                TextColumn::make('source')
                    ->label('Source')
                    ->state(function (Billing $record): string {
                        if ($record->billable_type === Order::class) {
                            return 'Order #'.($record->billable?->order_number ?? '-');
                        }

                        return 'Service: '.($record->billable?->service?->name ?? '-');
                    }),
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->state(fn (Billing $record): string => $record->billable?->customer?->name ?? '-')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $q) use ($search): void {
                            $q->whereHasMorph('billable', [Order::class], function (Builder $q) use ($search): void {
                                $q->whereHas('customer', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"));
                            })->orWhereHasMorph('billable', [ServiceRecord::class], function (Builder $q) use ($search): void {
                                $q->whereHas('customer', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"));
                            });
                        });
                    }),
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
                TextColumn::make('amount_paid')
                    ->label('Paid')
                    ->money('PHP')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->relationship('status', 'name')
                    ->preload(),
                SelectFilter::make('source_type')
                    ->label('Source Type')
                    ->options([
                        Order::class => 'Orders',
                        ServiceRecord::class => 'Services',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'],
                        fn (Builder $q, string $type) => $q->where('billable_type', $type)
                    )),
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
                    Action::make('view_source')
                        ->label(fn (Billing $record): string => $record->billable_type === Order::class ? 'View Order' : 'View Service Record')
                        ->icon(fn (Billing $record): string => $record->billable_type === Order::class ? 'heroicon-o-shopping-bag' : 'heroicon-o-clipboard-document-list')
                        ->color('gray')
                        ->url(fn (Billing $record): string => $record->billable_type === Order::class
                            ? OrderResource::getUrl('edit', ['record' => $record->billable_id])
                            : ServiceRecordResource::getUrl('edit', ['record' => $record->billable_id])
                        ),
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
