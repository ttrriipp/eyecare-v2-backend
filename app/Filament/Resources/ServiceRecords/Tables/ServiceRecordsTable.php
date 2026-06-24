<?php

namespace App\Filament\Resources\ServiceRecords\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServiceRecordsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Patient')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('service.name')
                    ->label('Service')
                    ->searchable(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('billing.status.name')
                    ->label('Billing Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'issued' => 'warning',
                        'partially_paid' => 'info',
                        'paid' => 'success',
                        'voided' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? ucwords(str_replace('_', ' ', $state))
                        : '—'
                    ),
                TextColumn::make('performed_at')
                    ->label('Performed')
                    ->date('M j, Y')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('performed_at', 'desc');
    }
}
