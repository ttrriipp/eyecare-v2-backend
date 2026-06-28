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
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        $advanceLabels = [
            'requested' => ['label' => 'Confirm', 'icon' => 'heroicon-o-check-circle', 'color' => 'success', 'next' => 'confirmed'],
            'confirmed' => ['label' => 'Start Processing', 'icon' => 'heroicon-o-wrench-screwdriver', 'color' => 'warning', 'next' => 'processing'],
            'processing' => ['label' => 'Mark Ready', 'icon' => 'heroicon-o-archive-box', 'color' => 'info', 'next' => 'ready_for_pickup'],
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
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (Order $record): string => match ($record->status?->name) {
                        'requested' => 'gray',
                        'confirmed' => 'info',
                        'processing' => 'warning',
                        'ready_for_pickup' => 'success',
                        'completed' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state)))
                    ->toggleable(),
                IconColumn::make('is_non_prescription')
                    ->label('Non-Rx')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_amount')
                    ->label('Total Price')
                    ->money('PHP')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Order Date')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->groups([
                Group::make('created_at')
                    ->label('Order Date')
                    ->date()
                    ->collapsible(),
            ])
            ->groupsInDropdownOnDesktop()
            ->filters([
                Filter::make('created_from')
                    ->label('Created from')
                    ->form([DatePicker::make('date')])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['date'],
                        fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date)
                    )),
                Filter::make('created_until')
                    ->label('Created until')
                    ->form([DatePicker::make('date')])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['date'],
                        fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date)
                    )),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
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
                        ->label('Cancel Order')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (Order $record): bool => ! in_array($record->status?->name, ['completed', 'cancelled'], true)
                            && ($record->status?->name === 'requested' || (auth()->user()?->isAdmin() ?? false)))
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
                    RestoreAction::make()->visible(fn (Order $record): bool => (auth()->user()?->isAdmin() ?? false) && $record->trashed()),
                    DeleteAction::make()->visible(fn (Order $record): bool => (auth()->user()?->isAdmin() ?? false) && ! $record->trashed()),
                    ForceDeleteAction::make()->visible(fn (Order $record): bool => (auth()->user()?->isAdmin() ?? false) && $record->trashed()),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
