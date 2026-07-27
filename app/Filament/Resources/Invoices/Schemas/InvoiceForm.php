<?php

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\Invoice;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                // ── Main (2/3) ──────────────────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
                    Section::make('Invoice Details')
                        ->schema([
                            TextInput::make('invoice_number')
                                ->label('Invoice #')
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('official_number')
                                ->label('Official SI #')
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('patient.full_name')
                                ->label('Patient')
                                ->disabled()
                                ->dehydrated(false),
                            Placeholder::make('status_badge')
                                ->label('Status')
                                ->content(fn (Invoice $record): string => Str::headline($record->status->value)),
                            TextInput::make('sale_type')
                                ->label('Sale Type')
                                ->disabled()
                                ->dehydrated(false),
                            DatePicker::make('issued_at')
                                ->label('Issued At')
                                ->native(false)
                                ->displayFormat('M d, Y g:i A')
                                ->disabled()
                                ->dehydrated(false),
                        ])
                        ->columns(2),

                    Section::make('Billing Information')
                        ->schema([
                            TextInput::make('sold_to_name')
                                ->label('Sold To')
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('registered_name')
                                ->label('Registered Name')
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('tin')
                                ->label('TIN')
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('business_address')
                                ->label('Business Address')
                                ->disabled()
                                ->dehydrated(false)
                                ->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->collapsible()
                        ->collapsed(),

                    Section::make('Line Items')
                        ->schema([
                            Repeater::make('items')
                                ->label('')
                                ->schema([
                                    TextInput::make('description')
                                        ->label('Description')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->columnSpanFull(),
                                    TextInput::make('quantity')
                                        ->label('Qty')
                                        ->disabled()
                                        ->dehydrated(false),
                                    TextInput::make('unit_price')
                                        ->label('Unit Price')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->prefix('₱'),
                                    TextInput::make('amount')
                                        ->label('Amount')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->prefix('₱'),
                                ])
                                ->columns(3)
                                ->disabled()
                                ->dehydrated(false),
                        ]),

                    Section::make('Notes')
                        ->schema([
                            Textarea::make('notes')
                                ->label('Notes')
                                ->columnSpanFull(),
                        ]),
                ]),

                // ── Sidebar (1/3) ────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make('Financial Summary')
                        ->schema([
                            Placeholder::make('subtotal')
                                ->label('Subtotal')
                                ->content(fn (Invoice $record): string => '₱'.number_format($record->subtotal, 2)),
                            Placeholder::make('discount_amount')
                                ->label('Discount')
                                ->content(fn (Invoice $record): string => '₱'.number_format($record->discount_amount, 2)),
                            Placeholder::make('tax_amount')
                                ->label('Tax')
                                ->content(fn (Invoice $record): string => '₱'.number_format($record->tax_amount, 2)),
                            Placeholder::make('total')
                                ->label('Total')
                                ->content(fn (Invoice $record): string => '₱'.number_format($record->total, 2)),
                            Placeholder::make('amount_paid')
                                ->label('Amount Paid')
                                ->content(fn (Invoice $record): string => '₱'.number_format($record->amount_paid, 2)),
                            Placeholder::make('balance_due')
                                ->label('Balance Due')
                                ->content(fn (Invoice $record): string => '₱'.number_format($record->balance_due, 2)),
                        ]),

                    Section::make('Payments')
                        ->schema([
                            Repeater::make('payments')
                                ->label('')
                                ->schema([
                                    Placeholder::make('payment_amount')
                                        ->label('Amount')
                                        ->content(fn ($state): string => '₱'.number_format($state['amount'] ?? 0, 2)),
                                    Placeholder::make('payment_method')
                                        ->label('Method')
                                        ->content(fn ($state): string => Str::headline($state['payment_method'] ?? '—')),
                                    Placeholder::make('payment_reference')
                                        ->label('Reference #')
                                        ->content(fn ($state): string => $state['reference_number'] ?? '—'),
                                    Placeholder::make('payment_status')
                                        ->label('Status')
                                        ->content(fn ($state): string => Str::headline($state['status'] ?? '—')),
                                ])
                                ->columns(2)
                                ->disabled()
                                ->dehydrated(false),
                        ])
                        ->collapsible()
                        ->collapsed(),
                ]),
            ]),
        ]);
    }
}
