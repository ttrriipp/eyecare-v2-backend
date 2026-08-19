<?php

namespace App\Filament\Resources\Quotations\Schemas;

use App\Enums\CommercialItemKind;
use App\Enums\QuotationStatus;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
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
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                // ── Main (2/3) ──────────────────────────────────────
                Grid::make(1)->columnSpan(['default' => 1, 'lg' => 2])->schema([
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
                                ->minDate(now())
                                ->disabled(fn (Quotation $record): bool => $record->status !== QuotationStatus::Draft),
                            Textarea::make('notes')
                                ->label('Patient Notes')
                                ->disabled(fn (Quotation $record): bool => $record->status !== QuotationStatus::Draft)
                                ->columnSpanFull(),
                        ])
                        ->columns(2),

                    Section::make('Confirmation Warning')
                        ->schema([
                            Placeholder::make('expiration_warning')
                                ->label('Warning')
                                ->content('This draft has expired. Revise Quotation before confirming the sale.')
                                ->color('danger')
                                ->visible(fn (Quotation $record): bool => $record->isExpired()),
                            Placeholder::make('prescription_warning')
                                ->label('Prescription warning')
                                ->content(fn (Quotation $record): string => $record->prescription?->isVoided() === true
                                    ? 'This prescription has been voided. Confirmation is unavailable.'
                                    : 'This prescription has been superseded. Select the current version before confirming.')
                                ->color('danger')
                                ->visible(fn (Quotation $record): bool => $record->prescription !== null
                                    && ($record->prescription->isVoided() || ! $record->prescription->isCurrentVersion())),
                        ])
                        ->visible(fn (Quotation $record): bool => $record->isExpired()
                            || ($record->prescription !== null
                                && ($record->prescription->isVoided() || ! $record->prescription->isCurrentVersion()))),

                    Section::make('Corrective Eyewear')
                        ->schema([
                            Placeholder::make('prescription_number')
                                ->label('Prescription')
                                ->content(fn (Quotation $record): string => $record->prescription?->prescription_number ?? '—')
                                ->url(fn (Quotation $record): ?string => $record->prescription
                                    ? PrescriptionResource::getUrl('view', ['record' => $record->prescription])
                                    : null),
                            Placeholder::make('prescription_author')
                                ->label('Prescribing Optometrist')
                                ->content(fn (Quotation $record): string => $record->prescription?->author?->full_name ?? '—'),
                            Placeholder::make('prescription_status')
                                ->label('Prescription Status')
                                ->content(fn (Quotation $record): string => match (true) {
                                    $record->prescription === null => '—',
                                    $record->prescription->isVoided() => 'Voided',
                                    $record->prescription->isCurrentVersion() => 'Current',
                                    default => 'Superseded',
                                })
                                ->badge()
                                ->color(fn (Quotation $record): string => match (true) {
                                    $record->prescription?->isVoided() === true => 'danger',
                                    $record->prescription?->isCurrentVersion() === true => 'success',
                                    default => 'warning',
                                }),
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
                                            CommercialItemKind::LensPackage, CommercialItemKind::LensOption => "{$state} pair",
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
                Grid::make(1)->columnSpan(['default' => 1, 'lg' => 1])->schema([
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
                            Placeholder::make('confirmed_at')
                                ->label('Confirmed')
                                ->content(fn (Quotation $record): string => $record->confirmed_at?->diffForHumans() ?? '—'),
                        ]),

                    Section::make('Decision')
                        ->schema([
                            Placeholder::make('confirmed_by')
                                ->label('Confirmed By')
                                ->content(fn (Quotation $record): string => $record->confirmer?->full_name ?? '—')
                                ->visible(fn (Quotation $record): bool => $record->status === QuotationStatus::Accepted),
                            Placeholder::make('decline_reason')
                                ->label('Decline Reason')
                                ->content(fn (Quotation $record): string => $record->decline_reason ?? '—')
                                ->visible(fn (Quotation $record): bool => $record->status === QuotationStatus::Declined),
                        ])
                        ->visible(fn (Quotation $record): bool => in_array($record->status, [
                            QuotationStatus::Accepted,
                            QuotationStatus::Declined,
                        ], true)),

                    Section::make('Workflow Stages')
                        ->schema([
                            Placeholder::make('optical_order_number')
                                ->label('Optical Order')
                                ->content(fn (Quotation $record): string => $record->jobOrder?->job_order_number ?? 'Created after confirmation'),
                            Placeholder::make('billing_record_number')
                                ->label('Billing Record')
                                ->content(fn (Quotation $record): string => $record->billingRecord?->billing_record_number ?? 'Created after confirmation'),
                        ])
                        ->visible(fn (Quotation $record): bool => $record->status === QuotationStatus::Accepted),
                ]),
            ]),
        ]);
    }
}
