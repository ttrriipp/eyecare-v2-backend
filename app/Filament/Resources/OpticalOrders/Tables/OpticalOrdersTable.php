<?php

namespace App\Filament\Resources\OpticalOrders\Tables;

use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Models\Quotation;
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
                TextColumn::make('quotation_number')
                    ->label('Order #')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('patient.full_name')
                    ->label('Patient')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('workflow_stage')
                    ->label('Stage')
                    ->getStateUsing(fn (Quotation $record): string => self::resolveWorkflowStage($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Draft' => 'gray',
                        'Awaiting Decision' => 'info',
                        'Confirmed' => 'warning',
                        'In Production' => 'primary',
                        'Ready for Pickup' => 'success',
                        'Completed' => 'success',
                        'Cancelled' => 'danger',
                        'Declined' => 'danger',
                        'Expired' => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('jobOrder.supplier_invoice_number')
                    ->label('Supplier Ref')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('PHP')
                    ->sortable(),

                TextColumn::make('payment_status_display')
                    ->label('Payment')
                    ->getStateUsing(fn (Quotation $record): ?string => $record->jobOrder?->billingRecord?->status?->getLabel())
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'Paid' => 'success',
                        'Partially Paid' => 'warning',
                        'Unpaid' => 'danger',
                        'Voided' => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('balance_due')
                    ->label('Balance')
                    ->getStateUsing(fn (Quotation $record): ?float => $record->jobOrder?->billingRecord?->balance_due)
                    ->money('PHP')
                    ->placeholder('—'),

                TextColumn::make('payment_due_date')
                    ->label('Due Date')
                    ->getStateUsing(fn (Quotation $record): ?string => $record->jobOrder?->billingRecord?->payment_due_date?->format('M j, Y'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Last Activity')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(QuotationStatus::class)
                    ->label('Quotation Status'),

                SelectFilter::make('job_order_status')
                    ->options(JobOrderStatus::class)
                    ->label('Fulfillment Status'),

                SelectFilter::make('payment_status')
                    ->options(BillingRecordStatus::class)
                    ->label('Payment Status'),
            ])
            ->recordActions([
                Action::make('view')
                    ->url(fn (Quotation $record) => OpticalOrderResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }

    private static function resolveWorkflowStage(Quotation $record): string
    {
        if ($record->status === QuotationStatus::Draft) {
            return 'Draft';
        }

        if ($record->status === QuotationStatus::Declined) {
            return 'Declined';
        }

        if ($record->status === QuotationStatus::Expired) {
            return 'Expired';
        }

        $jobOrder = $record->jobOrder;

        if ($jobOrder === null) {
            return $record->status === QuotationStatus::Presented
                ? 'Awaiting Decision'
                : 'Draft';
        }

        return match ($jobOrder->status) {
            JobOrderStatus::Queued => 'Confirmed',
            JobOrderStatus::InProgress => 'In Production',
            JobOrderStatus::ReadyForDispensing => 'Ready for Pickup',
            JobOrderStatus::Dispensed => 'Completed',
            JobOrderStatus::Cancelled => 'Cancelled',
            default => 'Unknown',
        };
    }
}
