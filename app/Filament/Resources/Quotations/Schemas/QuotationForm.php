<?php

namespace App\Filament\Resources\Quotations\Schemas;

use App\Models\Quotation;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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
                            TextInput::make('quotation_number')
                                ->label('Quotation #')
                                ->disabled()
                                ->dehydrated(false),
                            Placeholder::make('patient_name')
                                ->label('Patient')
                                ->content(fn (Quotation $record): string => $record->patient?->full_name ?? '—'),
                            Placeholder::make('status_badge')
                                ->label('Status')
                                ->content(fn (Quotation $record): string => Str::headline($record->status->value)),
                            DatePicker::make('valid_until')
                                ->label('Valid Until')
                                ->native(false)
                                ->displayFormat('M d, Y'),
                        ])
                        ->columns(2),

                    Section::make('Revision Items')
                        ->schema([
                            RepeatableEntry::make('latestRevision.items')
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
                                ->placeholder('No items recorded.'),
                        ]),

                    Section::make('Notes')
                        ->schema([
                            Textarea::make('notes')
                                ->label('Patient Notes')
                                ->columnSpanFull(),
                        ]),
                ]),

                // ── Sidebar (1/3) ────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make('Revision Summary')
                        ->schema([
                            Placeholder::make('revision_number')
                                ->label('Revision')
                                ->content(fn (Quotation $record): string => $record->latestRevision
                                    ? "#{$record->latestRevision->revision_number}"
                                    : '—'),
                            Placeholder::make('subtotal')
                                ->label('Subtotal')
                                ->content(fn (Quotation $record): string => $record->latestRevision
                                    ? '₱'.number_format($record->latestRevision->subtotal, 2)
                                    : '—'),
                            Placeholder::make('discount_amount')
                                ->label('Discount')
                                ->content(fn (Quotation $record): string => $record->latestRevision
                                    ? '₱'.number_format($record->latestRevision->discount_amount, 2)
                                    : '—'),
                            Placeholder::make('total')
                                ->label('Total')
                                ->content(fn (Quotation $record): string => $record->latestRevision
                                    ? '₱'.number_format($record->latestRevision->total, 2)
                                    : '—'),
                        ]),

                    Section::make('Timeline')
                        ->schema([
                            Placeholder::make('created_at')
                                ->label('Created')
                                ->content(fn (Quotation $record): string => $record->created_at?->diffForHumans() ?? '—'),
                            Placeholder::make('presented_at')
                                ->label('Presented')
                                ->content(fn (Quotation $record): string => $record->latestRevision?->presented_at?->diffForHumans() ?? '—'),
                            Placeholder::make('presentedBy.name')
                                ->label('Presented by')
                                ->content(fn (Quotation $record): string => $record->latestRevision?->presentedBy?->name ?? '—'),
                            Placeholder::make('accepted_at')
                                ->label('Accepted')
                                ->content(fn (Quotation $record): string => $record->latestRevision?->accepted_at?->diffForHumans() ?? '—'),
                            Placeholder::make('acceptedBy.name')
                                ->label('Accepted by')
                                ->content(fn (Quotation $record): string => $record->latestRevision?->acceptedBy?->name ?? '—'),
                        ]),
                ]),
            ]),
        ]);
    }
}
