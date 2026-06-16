<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Actions\Orders\UpdateOrderStatus;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_number')
                    ->label('Order #')
                    ->searchable(),
                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('status.name')
                    ->label('Status'),
                TextColumn::make('is_non_prescription')
                    ->label('Non-Rx')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('PHP'),
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->relationship('status', 'name'),
                SelectFilter::make('customer')
                    ->relationship('customer', 'name'),
            ])
            ->recordActions([
                Action::make('review')
                    ->label('Review')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->successNotificationTitle('Order moved to under review')
                    ->visible(fn (Order $record): bool => $record->status->name === 'requested')
                    ->action(fn (Order $record) => app(UpdateOrderStatus::class)->handle($record, 'under_review')),

                Action::make('confirm')
                    ->label('Confirm')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Confirm this order? Inventory will be deducted.')
                    ->successNotificationTitle('Order confirmed')
                    ->visible(fn (Order $record): bool => $record->status->name === 'under_review')
                    ->action(function (Order $record): void {
                        try {
                            app(UpdateOrderStatus::class)->handle($record, 'confirmed');
                        } catch (ValidationException $e) {
                            $messages = collect($e->errors())->flatten()->first() ?? 'Cannot confirm order.';
                            Notification::make()
                                ->title('Cannot confirm order')
                                ->body($messages)
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('prepare')
                    ->label('Preparing')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->successNotificationTitle('Order marked as preparing')
                    ->visible(fn (Order $record): bool => $record->status->name === 'confirmed')
                    ->action(fn (Order $record) => app(UpdateOrderStatus::class)->handle($record, 'preparing')),

                Action::make('ready')
                    ->label('Ready for Pickup')
                    ->icon('heroicon-o-archive-box')
                    ->color('info')
                    ->requiresConfirmation()
                    ->successNotificationTitle('Order ready for pickup')
                    ->visible(fn (Order $record): bool => $record->status->name === 'preparing')
                    ->action(fn (Order $record) => app(UpdateOrderStatus::class)->handle($record, 'ready_for_pickup')),

                Action::make('complete')
                    ->label('Complete')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->successNotificationTitle('Order completed')
                    ->visible(fn (Order $record): bool => $record->status->name === 'ready_for_pickup')
                    ->action(fn (Order $record) => app(UpdateOrderStatus::class)->handle($record, 'completed')),

                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Cancel this order? If confirmed, inventory will be restored.')
                    ->successNotificationTitle('Order cancelled')
                    ->visible(fn (Order $record): bool => ! in_array($record->status->name, ['completed', 'cancelled'], true))
                    ->action(fn (Order $record) => app(UpdateOrderStatus::class)->handle($record, 'cancelled')),

                EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
