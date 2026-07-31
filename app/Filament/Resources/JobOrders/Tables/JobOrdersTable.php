<?php

namespace App\Filament\Resources\JobOrders\Tables;

use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Enums\JobOrderStatus;
use App\Models\JobOrder;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class JobOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('job_order_number')
                    ->label('Job Order #')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('patient.first_name')
                    ->label('Patient'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (JobOrder $record): string => match ($record->status) {
                        JobOrderStatus::Queued => 'gray',
                        JobOrderStatus::InProgress => 'warning',
                        JobOrderStatus::ReadyForDispensing => 'info',
                        JobOrderStatus::Dispensed => 'success',
                        JobOrderStatus::Cancelled => 'danger',
                    })
                    ->formatStateUsing(fn (JobOrderStatus $state): string => match ($state) {
                        JobOrderStatus::Queued => 'Queued',
                        JobOrderStatus::InProgress => 'In Progress',
                        JobOrderStatus::ReadyForDispensing => 'Ready',
                        JobOrderStatus::Dispensed => 'Dispensed',
                        JobOrderStatus::Cancelled => 'Cancelled',
                    }),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(JobOrderStatus::class),
            ])
            ->recordActions([
                Action::make('start')
                    ->label('Start')
                    ->icon('heroicon-o-play')
                    ->color('warning')
                    ->visible(fn (JobOrder $record): bool => $record->status === JobOrderStatus::Queued)
                    ->requiresConfirmation()
                    ->action(function (JobOrder $record): void {
                        app(UpdateJobOrderStatus::class)->handle($record, 'in_progress');
                    }),

                Action::make('markReady')
                    ->label('Mark Ready')
                    ->icon('heroicon-o-check')
                    ->color('info')
                    ->visible(fn (JobOrder $record): bool => $record->status === JobOrderStatus::InProgress)
                    ->requiresConfirmation()
                    ->schema([
                        TextInput::make('supplier_invoice_number')
                            ->label('Supplier Invoice Number')
                            ->default(fn (JobOrder $record): ?string => $record->supplier_invoice_number)
                            ->required()
                            ->maxLength(100),
                    ])
                    ->action(function (array $data, JobOrder $record): void {
                        $record->update([
                            'supplier_invoice_number' => $data['supplier_invoice_number'],
                        ]);
                        app(UpdateJobOrderStatus::class)->handle($record, 'ready_for_dispensing');
                    }),

                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (JobOrder $record): bool => in_array($record->status, [JobOrderStatus::Queued, JobOrderStatus::InProgress], true))
                    ->requiresConfirmation()
                    ->action(function (JobOrder $record): void {
                        app(UpdateJobOrderStatus::class)->handle($record, 'cancelled');
                    }),

                EditAction::make()->label('View'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
