<?php

namespace App\Filament\Resources\Quotations\Tables;

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class QuotationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('quotation_number')
                    ->label('Quotation #')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('patient.full_name')
                    ->label('Patient')
                    ->searchable(['patient.first_name', 'patient.last_name'])
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (Quotation $record): string => match ($record->status) {
                        QuotationStatus::Draft => 'gray',
                        QuotationStatus::Presented => 'info',
                        QuotationStatus::Accepted => 'success',
                        QuotationStatus::Declined => 'danger',
                        QuotationStatus::Expired => 'warning',
                    }),
                TextColumn::make('total')
                    ->label('Total')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('valid_until')
                    ->label('Valid Until')
                    ->date('M j, Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(QuotationStatus::class),
            ])
            ->recordActions([
                EditAction::make()->label('View'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
