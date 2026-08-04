<?php

namespace App\Filament\Resources\OpticalOrders\Tables;

use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Models\JobOrder;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OpticalOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('job_order_number')
                    ->label('Order #')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('patient.full_name')
                    ->label('Patient')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (JobOrderStatus $state): string => match ($state) {
                        JobOrderStatus::Queued => 'Queued',
                        JobOrderStatus::InProgress => 'In Progress',
                        JobOrderStatus::ReadyForDispensing => 'Ready for Dispensing',
                        JobOrderStatus::Dispensed => 'Dispensed',
                        JobOrderStatus::Cancelled => 'Cancelled',
                    })
                    ->color(fn (JobOrderStatus $state): string => match ($state) {
                        JobOrderStatus::Queued => 'warning',
                        JobOrderStatus::InProgress => 'primary',
                        JobOrderStatus::ReadyForDispensing => 'success',
                        JobOrderStatus::Dispensed => 'success',
                        JobOrderStatus::Cancelled => 'danger',
                    }),

                TextColumn::make('quotation.patient.full_name')
                    ->label('Source Quotation')
                    ->placeholder('Direct order'),

                TextColumn::make('supplier_invoice_number')
                    ->label('Supplier Ref')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('PHP')
                    ->sortable(),

                TextColumn::make('billingRecord.status')
                    ->label('Payment')
                    ->badge()
                    ->color(fn (?BillingRecordStatus $state): string => match ($state) {
                        BillingRecordStatus::Paid => 'success',
                        BillingRecordStatus::PartiallyPaid => 'warning',
                        BillingRecordStatus::Unpaid => 'danger',
                        BillingRecordStatus::Voided => 'gray',
                        default => 'gray',
                    })
                    ->placeholder('—'),

                TextColumn::make('billingRecord.balance_due')
                    ->label('Balance')
                    ->money('PHP')
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(JobOrderStatus::class),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->url(fn (JobOrder $record) => OpticalOrderResource::getUrl('edit', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
