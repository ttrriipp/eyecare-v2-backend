<?php

namespace App\Filament\Resources\Billings\Tables;

use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
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
                    RestoreAction::make(),
                    DeleteAction::make(),
                    ForceDeleteAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
