<?php

namespace App\Filament\Resources\SmsNotifications\Tables;

use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class SmsNotificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('recipient')
                    ->searchable(),
                TextColumn::make('event')
                    ->label('Event')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state)))
                    ->color(fn (string $state): string => str_contains($state, 'appointment') ? 'info' : 'warning'),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (SmsNotification $record): string => match ($record->status?->name) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        'queued' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('message')
                    ->limit(60)
                    ->toggleable(),
                TextColumn::make('failure_reason')
                    ->label('Failure Reason')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Sent At')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->relationship('status', 'name')
                    ->options(fn () => NotificationStatus::query()->pluck('name', 'id')
                        ->mapWithKeys(fn ($name, $id) => [$id => ucfirst($name)])
                    ),
                SelectFilter::make('event')
                    ->options([
                        'appointment_confirmed' => 'Appointment Confirmed',
                        'appointment_rescheduled' => 'Appointment Rescheduled',
                        'appointment_cancelled' => 'Appointment Cancelled',
                        'order_confirmed' => 'Order Confirmed',
                        'order_ready' => 'Order Ready',
                        'order_completed' => 'Order Completed',
                        'order_cancelled' => 'Order Cancelled',
                    ]),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (SmsNotification $record): bool => $record->status?->name === 'failed')
                    ->requiresConfirmation()
                    ->action(function (SmsNotification $record): void {
                        $queuedStatus = NotificationStatus::query()->where('name', 'queued')->firstOrFail();
                        $record->update([
                            'notification_status_id' => $queuedStatus->id,
                            'failure_reason' => null,
                        ]);
                        Notification::make()->title('SMS queued for retry')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_retry')
                        ->label('Retry Selected')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                        ->action(function (Collection $records): void {
                            $queuedStatus = NotificationStatus::query()->where('name', 'queued')->firstOrFail();
                            $count = 0;

                            foreach ($records as $record) {
                                if ($record->status?->name !== 'failed') {
                                    continue;
                                }

                                $record->update([
                                    'notification_status_id' => $queuedStatus->id,
                                    'failure_reason' => null,
                                ]);
                                $count++;
                            }

                            Notification::make()
                                ->title("{$count} SMS queued for retry")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
