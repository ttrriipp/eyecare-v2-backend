<?php

namespace App\Filament\Resources\Quotations\Schemas;

use App\Enums\CommercialItemKind;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Illuminate\Support\Str;

class QuotationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                // ── Main (2/3) ──────────────────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
                    Section::make('Quotation Details')
                        ->schema([
                            Placeholder::make('quotation_number')
                                ->label('Quotation #')
                                ->content(fn (Quotation $record): string => $record->quotation_number ?? '—'),
                            Placeholder::make('patient_name')
                                ->label('Patient')
                                ->content(fn (Quotation $record): string => $record->patient?->full_name ?? '—'),
                            Placeholder::make('status_badge')
                                ->label('Status')
                                ->content(fn (Quotation $record): string => Str::headline($record->status->value))
                                ->badge()
                                ->size(TextSize::Large)
                                ->color(fn (Quotation $record): string => match ($record->status) {
                                    QuotationStatus::Draft => 'gray',
                                    QuotationStatus::Accepted => 'success',
                                    QuotationStatus::Declined => 'danger',
                                }),
                            DatePicker::make('valid_until')
                                ->label('Valid Until')
                                ->required()
                                ->native(false)
                                ->displayFormat('M d, Y')
                                ->suffixIcon('heroicon-o-calendar-days')
                                ->minDate(now()),
                            Textarea::make('notes')
                                ->label('Patient Notes')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),

                    Section::make('Corrective Eyewear')
                        ->schema([
                            Placeholder::make('prescription_number')
                                ->label('Prescription')
                                ->content(fn (Quotation $record): string => $record->prescription?->prescription_number ?? '—'),
                            Placeholder::make('prescription_author')
                                ->label('Prescribing Optometrist')
                                ->content(fn (Quotation $record): string => $record->prescription?->author?->full_name ?? '—'),
                            Placeholder::make('prescription_status')
                                ->label('Prescription Status')
                                ->content(fn (Quotation $record): string => $record->prescription === null
                                    ? '—'
                                    : ($record->prescription->isCurrentVersion() ? 'Current' : 'Superseded'))
                                ->badge()
                                ->color(fn (Quotation $record): string => $record->prescription?->isCurrentVersion() === true
                                    ? 'success'
                                    : 'warning'),
                        ])
                        ->columns(3)
                        ->visible(fn (Quotation $record): bool => $record->prescription !== null),

                    Section::make('Product Items')
                        ->schema([
                            RepeatableEntry::make('productItems')
                                ->hiddenLabel()
                                ->table([
                                    TableColumn::make('Description'),
                                    TableColumn::make('Quantity'),
                                    TableColumn::make('Unit Price'),
                                    TableColumn::make('Amount'),
                                ])
                                ->schema([
                                    TextEntry::make('description')
                                        ->hiddenLabel()
                                        ->wrap(),
                                    TextEntry::make('quantity')
                                        ->hiddenLabel()
                                        ->formatStateUsing(fn ($state, $record): string => match ($record?->item_kind) {
                                            CommercialItemKind::LensPackage => "{$state} pair",
                                            default => (string) $state,
                                        }),
                                    TextEntry::make('unit_price')
                                        ->hiddenLabel()
                                        ->money('PHP'),
                                    TextEntry::make('amount')
                                        ->hiddenLabel()
                                        ->money('PHP'),
                                ])
                                ->placeholder('No product items.'),
                        ]),

                    Section::make('Service Items')
                        ->schema([
                            RepeatableEntry::make('serviceItems')
                                ->hiddenLabel()
                                ->table([
                                    TableColumn::make('Description'),
                                    TableColumn::make('Quantity'),
                                    TableColumn::make('Unit Price'),
                                    TableColumn::make('Amount'),
                                ])
                                ->schema([
                                    TextEntry::make('description')
                                        ->hiddenLabel()
                                        ->wrap(),
                                    TextEntry::make('quantity')
                                        ->hiddenLabel(),
                                    TextEntry::make('unit_price')
                                        ->hiddenLabel()
                                        ->money('PHP'),
                                    TextEntry::make('amount')
                                        ->hiddenLabel()
                                        ->money('PHP'),
                                ])
                                ->placeholder('No service items.'),
                        ]),
                ]),

                // ── Sidebar (1/3) ────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make('Summary')
                        ->schema([
                            Placeholder::make('subtotal')
                                ->label('Subtotal')
                                ->content(fn (Quotation $record): string => '₱'.number_format($record->subtotal, 2)),
                            Placeholder::make('discount_amount')
                                ->label('Discount')
                                ->content(fn (Quotation $record): string => '₱'.number_format($record->discount_amount, 2)),
                            Placeholder::make('total')
                                ->label('Total')
                                ->content(fn (Quotation $record): string => '₱'.number_format($record->total, 2)),
                        ]),

                    Section::make('Timeline')
                        ->schema([
                            Placeholder::make('created_at')
                                ->label('Created')
                                ->content(fn (Quotation $record): string => $record->created_at?->diffForHumans() ?? '—'),
                            Placeholder::make('presented_at')
                                ->label('Presented')
                                ->content(fn (Quotation $record): string => $record->presented_at?->diffForHumans() ?? '—'),
                            Placeholder::make('confirmed_at')
                                ->label('Confirmed')
                                ->content(fn (Quotation $record): string => $record->confirmed_at?->diffForHumans() ?? '—'),
                        ]),
                ]),
            ]),
        ]);
    }
}
