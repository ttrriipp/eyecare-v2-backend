<?php

namespace App\Filament\Resources\BillingRecords\Tables;

use App\Enums\BillingRecordStatus;
use App\Models\BillingRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BillingRecordsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('billing_record_number')
                    ->label('Billing #')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('patient.full_name')
                    ->label('Patient')
                    ->searchable(),

                TextColumn::make('jobOrder.job_order_number')
                    ->label('Job Order')
                    ->sortable(),

                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('PHP')
                    ->sortable(),

                TextColumn::make('amount_paid')
                    ->label('Paid')
                    ->money('PHP'),

                TextColumn::make('balance_due')
                    ->label('Balance')
                    ->money('PHP')
                    ->color(fn (BillingRecord $record): string => match (true) {
                        (float) $record->balance_due <= 0 => 'success',
                        $record->isOverdue() => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('payment_due_date')
                    ->label('Due Date')
                    ->date('M j, Y')
                    ->placeholder('—')
                    ->color(fn (BillingRecord $record): string => $record->isOverdue() ? 'danger' : 'gray'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (BillingRecordStatus $state): string => match ($state) {
                        BillingRecordStatus::Unpaid => 'gray',
                        BillingRecordStatus::PartiallyPaid => 'warning',
                        BillingRecordStatus::Paid => 'success',
                        BillingRecordStatus::Voided => 'danger',
                    }),

                TextColumn::make('recorded_at')
                    ->label('Recorded')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(BillingRecordStatus::class),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
