<?php

namespace App\Filament\Resources\Billings\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BillingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Billing Details')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('billing_number')
                            ->label('Billing #'),
                        TextEntry::make('customer.name')
                            ->label('Customer'),
                        TextEntry::make('order.order_number')
                            ->label('Order #')
                            ->placeholder('—'),
                        TextEntry::make('status.name')
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
                        TextEntry::make('subtotal')
                            ->label('Subtotal')
                            ->money('PHP'),
                        TextEntry::make('discount_amount')
                            ->label('Discount')
                            ->money('PHP'),
                        TextEntry::make('total_amount')
                            ->label('Total')
                            ->money('PHP'),
                        TextEntry::make('amount_paid')
                            ->label('Amount Paid')
                            ->money('PHP'),
                        TextEntry::make('balance_due')
                            ->label('Balance Due')
                            ->money('PHP'),
                        TextEntry::make('issued_at')
                            ->label('Issued At')
                            ->dateTime('M j, Y'),
                    ]),
            ]);
    }
}
