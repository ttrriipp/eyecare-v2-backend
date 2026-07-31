<?php

namespace App\Filament\Resources\OpticalOrders\Schemas;

use App\Enums\QuotationStatus;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class OpticalOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('quotation_number')
                    ->label('Order #')
                    ->content(fn ($record) => $record?->quotation_number ?? '—'),

                Placeholder::make('patient_name')
                    ->label('Patient')
                    ->content(fn ($record) => $record?->patient?->full_name ?? '—'),

                Select::make('status')
                    ->options(QuotationStatus::class)
                    ->disabled(),

                Placeholder::make('fulfillment_status')
                    ->label('Fulfillment')
                    ->content(fn ($record) => $record?->jobOrder?->status?->value ?? 'No order yet'),

                Placeholder::make('payment_status')
                    ->label('Payment')
                    ->content(fn ($record) => $record?->jobOrder?->billingRecord?->status?->value ?? '—'),

                Placeholder::make('total')
                    ->label('Total')
                    ->content(fn ($record) => $record?->latestRevision?->total ? number_format($record->latestRevision->total, 2) : '—'),

                Textarea::make('notes')
                    ->label('Notes')
                    ->disabled()
                    ->columnSpanFull(),
            ]);
    }
}
