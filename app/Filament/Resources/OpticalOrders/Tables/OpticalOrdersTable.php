<?php

namespace App\Filament\Resources\OpticalOrders\Tables;

use App\Enums\QuotationStatus;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Models\Quotation;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OpticalOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('quotation_number')
                    ->label('Order #')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('patient.first_name')
                    ->label('Patient'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (QuotationStatus $state) => match ($state) {
                        QuotationStatus::Draft => 'gray',
                        QuotationStatus::Presented => 'info',
                        QuotationStatus::Accepted => 'success',
                        QuotationStatus::Declined => 'danger',
                        QuotationStatus::Expired => 'warning',
                    }),

                TextColumn::make('jobOrder.status')
                    ->label('Fulfillment')
                    ->badge()
                    ->placeholder('No order yet'),

                TextColumn::make('jobOrder.billingRecord.status')
                    ->label('Payment')
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('PHP')
                    ->sortable(),

                TextColumn::make('valid_until')
                    ->label('Valid Until')
                    ->date('M j, Y')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(QuotationStatus::class),
            ])
            ->recordActions([
                Action::make('view')
                    ->url(fn (Quotation $record) => OpticalOrderResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
