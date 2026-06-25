<?php

namespace App\Filament\Resources\Billings\Schemas;

use App\Models\Billing;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;

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
                            ->label('Patient'),
                        TextEntry::make('order.order_number')
                            ->label('Order #')
                            ->placeholder('—'),
                        TextEntry::make('appointment.scheduled_at')
                            ->label('Appointment')
                            ->formatStateUsing(fn ($state, Billing $record): string => $state
                                ? $state->format('M j, Y g:i A').' — '.($record->appointment?->visitReason?->name ?? '')
                                : '—'
                            )
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
                        TextEntry::make('issued_at')
                            ->label('Issued At')
                            ->dateTime('M j, Y'),
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
                    ]),

                Section::make('Line Items')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->table([
                                TableColumn::make('Type'),
                                TableColumn::make('Description'),
                                TableColumn::make('Qty')->alignment(Alignment::End),
                                TableColumn::make('Unit Price')->alignment(Alignment::End),
                                TableColumn::make('Amount')->alignment(Alignment::End),
                            ])
                            ->schema([
                                TextEntry::make('type')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'product' => 'info',
                                        'service' => 'success',
                                        default => 'gray',
                                    })
                                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                                TextEntry::make('description'),
                                TextEntry::make('quantity'),
                                TextEntry::make('unit_price')
                                    ->money('PHP'),
                                TextEntry::make('amount')
                                    ->money('PHP'),
                            ]),
                    ]),
            ]);
    }
}
