<?php

namespace App\Filament\Resources\Billings\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class BillingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order.order_number')
                    ->label('Order #')
                    ->searchable(),
                TextColumn::make('order.customer.name')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('status.name')
                    ->label('Status'),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('PHP'),
                TextColumn::make('amount_paid')
                    ->label('Paid')
                    ->money('PHP'),
                TextColumn::make('balance_due')
                    ->label('Balance Due')
                    ->money('PHP'),
                TextColumn::make('created_at')
                    ->label('Generated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->relationship('status', 'name'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                RestoreAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
