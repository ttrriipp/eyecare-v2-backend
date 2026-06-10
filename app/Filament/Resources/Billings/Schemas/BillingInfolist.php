<?php

namespace App\Filament\Resources\Billings\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class BillingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('order.order_number')
                    ->label('Order #'),
                TextEntry::make('order.customer.name')
                    ->label('Customer'),
                TextEntry::make('status.name')
                    ->label('Status'),
                TextEntry::make('total_amount')
                    ->label('Total Amount')
                    ->money('PHP'),
                TextEntry::make('amount_paid')
                    ->label('Amount Paid')
                    ->money('PHP'),
                TextEntry::make('balance_due')
                    ->label('Balance Due')
                    ->money('PHP'),
                TextEntry::make('issued_at')
                    ->label('Issued At')
                    ->dateTime(),
                TextEntry::make('notes')
                    ->label('Notes')
                    ->columnSpanFull(),
            ]);
    }
}
