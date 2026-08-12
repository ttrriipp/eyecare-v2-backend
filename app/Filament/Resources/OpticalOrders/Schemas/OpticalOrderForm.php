<?php

namespace App\Filament\Resources\OpticalOrders\Schemas;

use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Models\JobOrder;
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

class OpticalOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                // ── Main (2/3) ──────────────────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
                    Section::make('Order Details')
                        ->schema([
                            Placeholder::make('job_order_number')
                                ->label('Order #')
                                ->content(fn (JobOrder $record): string => $record->job_order_number ?? '—'),
                            Placeholder::make('patient_name')
                                ->label('Patient')
                                ->content(fn (JobOrder $record): string => $record->patient?->full_name ?? '—'),
                            Placeholder::make('status_badge')
                                ->label('Status')
                                ->content(fn (JobOrder $record): string => match ($record->status) {
                                    JobOrderStatus::Queued => 'Confirmed',
                                    JobOrderStatus::InProgress => 'Processing',
                                    JobOrderStatus::ReadyForDispensing => 'Ready for Pickup',
                                    JobOrderStatus::Dispensed => 'Completed',
                                    JobOrderStatus::Cancelled => 'Cancelled',
                                })
                                ->badge()
                                ->color(fn (JobOrder $record): string => match ($record->status) {
                                    JobOrderStatus::Queued => 'warning',
                                    JobOrderStatus::InProgress => 'primary',
                                    JobOrderStatus::ReadyForDispensing => 'success',
                                    JobOrderStatus::Dispensed => 'success',
                                    JobOrderStatus::Cancelled => 'danger',
                                }),
                            Placeholder::make('fulfillment_mode')
                                ->label('Fulfillment')
                                ->content(fn (JobOrder $record): string => Str::headline($record->fulfillment_mode)
                                    .($record->uses_external_supplier ? ' (External Supplier)' : ''))
                                ->badge()
                                ->color(fn (JobOrder $record): string => $record->uses_external_supplier ? 'warning' : 'info'),
                            TextInput::make('supplier_invoice_number')
                                ->label('Supplier Invoice Number')
                                ->maxLength(100)
                                ->visible(fn (JobOrder $record): bool => $record->uses_external_supplier
                                    || filled($record->supplier_invoice_number)),
                            Textarea::make('notes')
                                ->label('Notes')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),

                    Section::make('Product Items')
                        ->schema([
                            RepeatableEntry::make('items')
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
                ]),

                // ── Sidebar (1/3) ────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make('Payment')
                        ->schema([
                            Placeholder::make('total_amount')
                                ->label('Total Amount')
                                ->content(fn (JobOrder $record): string => '₱'.number_format((float) $record->total_amount, 2)),
                            Placeholder::make('billing_status')
                                ->label('Status')
                                ->content(fn (JobOrder $record): string => $record->billingRecord
                                    ? Str::headline($record->billingRecord->status->value)
                                    : 'No billing record')
                                ->badge()
                                ->color(fn (JobOrder $record): string => match ($record->billingRecord?->status) {
                                    BillingRecordStatus::Paid => 'success',
                                    BillingRecordStatus::PartiallyPaid => 'warning',
                                    BillingRecordStatus::Unpaid => 'danger',
                                    BillingRecordStatus::Voided => 'gray',
                                    default => 'gray',
                                }),
                            Placeholder::make('billing_balance')
                                ->label('Balance Due')
                                ->content(fn (JobOrder $record): string => '₱'.number_format((float) ($record->billingRecord?->balance_due ?? 0), 2))
                                ->visible(fn (JobOrder $record): bool => $record->billingRecord !== null),
                            Placeholder::make('billing_amount_paid')
                                ->label('Amount Paid')
                                ->content(fn (JobOrder $record): string => '₱'.number_format((float) ($record->billingRecord?->amount_paid ?? 0), 2))
                                ->visible(fn (JobOrder $record): bool => $record->billingRecord !== null),
                            Placeholder::make('billing_due_date')
                                ->label('Payment Due Date')
                                ->content(fn (JobOrder $record): string => $record->billingRecord?->payment_due_date?->format('M j, Y') ?? 'Not set')
                                ->visible(fn (JobOrder $record): bool => $record->billingRecord !== null),
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
                                ->content(fn (JobOrder $record): string => $record->dispensed_at?->diffForHumans() ?? '—')
                                ->visible(fn (JobOrder $record): bool => $record->status === JobOrderStatus::Dispensed),
                            Placeholder::make('cancelled_at')
                                ->label('Cancelled')
                                ->content(fn (JobOrder $record): string => $record->cancelled_at?->diffForHumans() ?? '—')
                                ->visible(fn (JobOrder $record): bool => $record->status === JobOrderStatus::Cancelled),
                        ]),

                    Section::make('Dispensing')
                        ->visible(fn (JobOrder $record): bool => $record->dispensingEvents->isNotEmpty())
                        ->schema([
                            Placeholder::make('recipient_name')
                                ->label('Recipient')
                                ->content(fn (JobOrder $record): string => $record->dispensingEvents->last()?->recipient_name ?? '—'),
                            Placeholder::make('dispensed_by')
                                ->label('Dispensed By')
                                ->content(fn (JobOrder $record): string => $record->dispensingEvents->last()?->dispensedBy?->full_name ?? '—'),
                            Placeholder::make('dispensing_notes')
                                ->label('Notes')
                                ->content(fn (JobOrder $record): string => $record->dispensingEvents->last()?->notes ?? '—')
                                ->visible(fn (JobOrder $record): bool => filled($record->dispensingEvents->last()?->notes)),
                        ]),
                ]),
            ]),
        ]);
    }
}
