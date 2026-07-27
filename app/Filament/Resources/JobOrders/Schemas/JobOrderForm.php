<?php

namespace App\Filament\Resources\JobOrders\Schemas;

use App\Models\JobOrder;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class JobOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                // ── Main (2/3) ──────────────────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
                    Section::make('Job Order Details')
                        ->schema([
                            TextInput::make('job_order_number')
                                ->label('Job Order #')
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('patient.full_name')
                                ->label('Patient')
                                ->disabled()
                                ->dehydrated(false),
                            Placeholder::make('status_badge')
                                ->label('Status')
                                ->content(fn (JobOrder $record): string => Str::headline($record->status->value)),
                            TextInput::make('total_amount')
                                ->label('Total Amount')
                                ->disabled()
                                ->dehydrated(false)
                                ->prefix('₱'),
                        ])
                        ->columns(2),

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
                    Section::make('Linked Records')
                        ->schema([
                            Placeholder::make('encounter_id')
                                ->label('Encounter')
                                ->content(fn (JobOrder $record): string => $record->encounter
                                    ? "#{$record->encounter->id}"
                                    : '—'),
                            Placeholder::make('prescription_id')
                                ->label('Prescription')
                                ->content(fn (JobOrder $record): string => $record->prescription
                                    ? "#{$record->prescription->id}"
                                    : '—'),
                            Placeholder::make('quotation_revision_id')
                                ->label('Quotation Revision')
                                ->content(fn (JobOrder $record): string => $record->quotationRevision
                                    ? "Revision #{$record->quotationRevision->revision_number}"
                                    : '—'),
                        ]),

                    Section::make('Timeline')
                        ->schema([
                            Placeholder::make('created_at')
                                ->label('Created')
                                ->content(fn (JobOrder $record): string => $record->created_at?->diffForHumans() ?? '—'),
                            Placeholder::make('started_at')
                                ->label('Started')
                                ->content(fn (JobOrder $record): string => $record->started_at?->diffForHumans() ?? '—'),
                            Placeholder::make('ready_at')
                                ->label('Ready')
                                ->content(fn (JobOrder $record): string => $record->ready_at?->diffForHumans() ?? '—'),
                            Placeholder::make('dispensed_at')
                                ->label('Dispensed')
                                ->content(fn (JobOrder $record): string => $record->dispensed_at?->diffForHumans() ?? '—'),
                            Placeholder::make('cancelled_at')
                                ->label('Cancelled')
                                ->content(fn (JobOrder $record): string => $record->cancelled_at?->diffForHumans() ?? '—'),
                        ]),
                ]),
            ]),
        ]);
    }
}
