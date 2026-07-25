<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Invoice #')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('official_number')
                    ->label('Official SI #')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('patient.full_name')
                    ->label('Patient')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (Invoice $record): string => match ($record->status) {
                        InvoiceStatus::Draft => 'gray',
                        InvoiceStatus::Issued => 'info',
                        InvoiceStatus::PartiallyPaid => 'warning',
                        InvoiceStatus::Paid => 'success',
                        InvoiceStatus::Voided => 'danger',
                    }),
                TextColumn::make('total')
                    ->label('Total')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('amount_paid')
                    ->label('Paid')
                    ->money('PHP'),
                TextColumn::make('balance_due')
                    ->label('Balance')
                    ->money('PHP')
                    ->color(fn (Invoice $record): string => (float) $record->balance_due > 0 ? 'warning' : 'success'),
                TextColumn::make('issued_at')
                    ->label('Issued')
                    ->dateTime('M j, Y')
                    ->placeholder('Draft')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(InvoiceStatus::class),
            ])
            ->recordActions([
                EditAction::make()->label('View'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
