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

                TextColumn::make('source_context')
                    ->label('Source')
                    ->getStateUsing(fn (BillingRecord $record): string => $record->getSourceContext())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Optical Order' => 'info',
                        'Encounter' => 'warning',
                        'Combined' => 'success',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('jobOrder.job_order_number')
                    ->label('Optical Order')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('encounter.encounter_number')
                    ->label('Encounter')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('items_summary')
                    ->label('Items')
                    ->getStateUsing(function (BillingRecord $record): string {
                        $items = $record->items;
                        if ($items->isEmpty()) {
                            return '—';
                        }
                        $productCount = $items->where('item_type', 'product')->count();
                        $serviceCount = $items->where('item_type', 'service')->count();
                        $parts = [];
                        if ($productCount > 0) {
                            $parts[] = "{$productCount} products";
                        }
                        if ($serviceCount > 0) {
                            $parts[] = "{$serviceCount} services";
                        }

                        return implode(', ', $parts);
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

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

                SelectFilter::make('source')
                    ->options([
                        'optical' => 'Optical Order',
                        'encounter' => 'Encounter',
                        'combined' => 'Combined',
                    ])
                    ->query(function ($query, $data) {
                        if ($data['value'] === 'optical') {
                            return $query->whereNotNull('job_order_id')->whereNull('encounter_id');
                        }
                        if ($data['value'] === 'encounter') {
                            return $query->whereNull('job_order_id')->whereNotNull('encounter_id');
                        }
                        if ($data['value'] === 'combined') {
                            return $query->whereNotNull('job_order_id')->whereNotNull('encounter_id');
                        }

                        return $query;
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
