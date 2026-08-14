<?php

namespace App\Filament\Resources\OpticalOrders\Tables;

use App\Actions\JobOrders\UpdateJobOrderStatus;
use App\Actions\OpticalOrders\CancelOpticalOrder;
use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Models\JobOrder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

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
                        JobOrderStatus::Queued => 'Confirmed',
                        JobOrderStatus::InProgress => 'Processing',
                        JobOrderStatus::ReadyForDispensing => 'Ready for Pickup',
                        JobOrderStatus::Dispensed => 'Completed',
                        JobOrderStatus::Cancelled => 'Cancelled',
                    })
                    ->color(fn (JobOrderStatus $state): string => match ($state) {
                        JobOrderStatus::Queued => 'warning',
                        JobOrderStatus::InProgress => 'primary',
                        JobOrderStatus::ReadyForDispensing => 'success',
                        JobOrderStatus::Dispensed => 'success',
                        JobOrderStatus::Cancelled => 'danger',
                    }),

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
                ActionGroup::make([
                    Action::make('view')
                        ->label('View')
                        ->icon('heroicon-o-eye')
                        ->url(fn (JobOrder $record) => OpticalOrderResource::getUrl('edit', ['record' => $record])),
                    Action::make('start')
                        ->label('Start Processing')
                        ->icon('heroicon-o-play')
                        ->color('warning')
                        ->visible(fn (JobOrder $record): bool => $record->status === JobOrderStatus::Queued)
                        ->requiresConfirmation()
                        ->modalHeading('Start Processing')
                        ->modalDescription('Begin processing this optical order.')
                        ->action(function (JobOrder $record): void {
                            try {
                                app(UpdateJobOrderStatus::class)->handle($record, 'in_progress');
                                Notification::make()->title('Order started')->success()->send();
                            } catch (ValidationException $e) {
                                Notification::make()->title('Cannot start order')->body($e->getMessage())->danger()->send();
                            }
                        }),
                    Action::make('markReady')
                        ->label('Mark Ready')
                        ->icon('heroicon-o-check')
                        ->color('info')
                        ->visible(fn (JobOrder $record): bool => $record->status === JobOrderStatus::InProgress)
                        ->requiresConfirmation()
                        ->modalHeading('Mark Ready for Pickup')
                        ->modalDescription('Mark this order as ready for patient pickup.')
                        ->action(function (JobOrder $record): void {
                            try {
                                app(UpdateJobOrderStatus::class)->handle($record, 'ready_for_dispensing');
                                Notification::make()->title('Order marked ready')->success()->send();
                            } catch (ValidationException $e) {
                                Notification::make()->title('Cannot mark ready')->body($e->getMessage())->danger()->send();
                            }
                        }),
                    Action::make('cancel')
                        ->label('Cancel')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (JobOrder $record): bool => in_array($record->status, [JobOrderStatus::Queued, JobOrderStatus::InProgress], true))
                        ->requiresConfirmation()
                        ->modalHeading('Cancel Order')
                        ->modalDescription('This will cancel the order and reverse any committed inventory.')
                        ->schema([
                            Textarea::make('cancellation_reason')
                                ->label('Reason')
                                ->nullable(),
                        ])
                        ->action(function (JobOrder $record, array $data): void {
                            try {
                                app(CancelOpticalOrder::class)->handle(
                                    $record,
                                    $data['cancellation_reason'] ?? null,
                                );
                                Notification::make()->title('Order cancelled')->success()->send();
                            } catch (ValidationException $e) {
                                Notification::make()->title('Cannot cancel order')->body($e->getMessage())->danger()->send();
                            }
                        }),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
