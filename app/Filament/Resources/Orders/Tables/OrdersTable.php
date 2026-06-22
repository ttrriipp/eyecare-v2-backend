<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Actions\Orders\UpdateOrderStatus;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        $advanceLabels = [
            'requested' => ['label' => 'Confirm', 'icon' => 'heroicon-o-check-circle', 'color' => 'success', 'next' => 'confirmed'],
            'confirmed' => ['label' => 'Start Preparing', 'icon' => 'heroicon-o-wrench-screwdriver', 'color' => 'warning', 'next' => 'preparing'],
            'preparing' => ['label' => 'Mark Ready', 'icon' => 'heroicon-o-archive-box', 'color' => 'info', 'next' => 'ready_for_pickup'],
            'ready_for_pickup' => ['label' => 'Complete', 'icon' => 'heroicon-o-check-badge', 'color' => 'success', 'next' => 'completed'],
        ];

        return $table
            ->columns([
                TextColumn::make('order_number')
                    ->label('Number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (Order $record): string => match ($record->status?->name) {
                        'requested' => 'gray',
                        'confirmed' => 'info',
                        'preparing' => 'warning',
                        'ready_for_pickup' => 'success',
                        'completed' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state))),
                IconColumn::make('is_non_prescription')
                    ->label('Non-Rx')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),
                TextColumn::make('total_amount')
                    ->label('Total Price')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Order Date')
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('customer')
                    ->relationship('customer', 'name'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('advance')
                    ->label(fn (Order $record): string => $advanceLabels[$record->status?->name]['label'] ?? '')
                    ->icon(fn (Order $record): string => $advanceLabels[$record->status?->name]['icon'] ?? 'heroicon-o-arrow-right')
                    ->color(fn (Order $record): string => $advanceLabels[$record->status?->name]['color'] ?? 'gray')
                    ->visible(fn (Order $record): bool => isset($advanceLabels[$record->status?->name]))
                    ->requiresConfirmation()
                    ->action(function (Order $record) use ($advanceLabels): void {
                        $next = $advanceLabels[$record->status->name]['next'] ?? null;
                        if (! $next) {
                            return;
                        }

                        try {
                            app(UpdateOrderStatus::class)->handle($record, $next);
                            Notification::make()->title('Order status updated')->success()->send();
                        } catch (ValidationException $e) {
                            $message = collect($e->errors())->flatten()->first() ?? 'Cannot advance order.';
                            Notification::make()->title('Cannot advance order')->body($message)->danger()->send();
                        }
                    }),

                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Order $record): bool => ! in_array($record->status?->name, ['completed', 'cancelled'], true))
                    ->requiresConfirmation()
                    ->action(function (Order $record): void {
                        try {
                            app(UpdateOrderStatus::class)->handle($record, 'cancelled');
                            Notification::make()->title('Order cancelled')->success()->send();
                        } catch (ValidationException $e) {
                            $message = collect($e->errors())->flatten()->first() ?? 'Cannot cancel order.';
                            Notification::make()->title('Cannot cancel order')->body($message)->danger()->send();
                        }
                    }),

                ActionGroup::make([
                    EditAction::make(),
                    RestoreAction::make(),
                    DeleteAction::make(),
                    ForceDeleteAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
