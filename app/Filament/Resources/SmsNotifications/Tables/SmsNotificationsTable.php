<?php

namespace App\Filament\Resources\SmsNotifications\Tables;

use App\Jobs\SendSmsJob;
use App\Models\NotificationStatus;
use App\Models\SmsNotification;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SmsNotificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['appointment', 'jobOrder', 'status']))
            ->columns([
                TextColumn::make('recipient')
                    ->searchable(),
                TextColumn::make('reference')
                    ->label('Reference')
                    ->state(fn (SmsNotification $record): ?string => $record->appointment?->appointment_number
                        ?? $record->jobOrder?->job_order_number)
                    ->placeholder('—'),
                TextColumn::make('event')
                    ->label('Event')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', ucfirst($state)))
                    ->color(fn (string $state): string => str_contains($state, 'appointment') ? 'info' : 'warning'),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'sent' ? 'Accepted by provider' : ucfirst($state))
                    ->color(fn (SmsNotification $record): string => match ($record->status?->name) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        'queued' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('delivery_state')
                    ->label('Delivery')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'sending' => 'Sending',
                        'accepted' => 'Accepted',
                        'pending' => 'Pending',
                        'dispatched' => 'Dispatched',
                        'sent' => 'Sent',
                        'delivered' => 'Delivered',
                        'failed' => 'Delivery failed',
                        'unknown' => 'Outcome unknown',
                        default => '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'accepted', 'pending', 'dispatched' => 'info',
                        'sent', 'delivered' => 'success',
                        'failed', 'unknown' => 'danger',
                        'sending' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                TextColumn::make('message')
                    ->limit(60)
                    ->toggleable(),
                TextColumn::make('failure_reason')
                    ->label('Failure Reason')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provider_name')
                    ->label('Provider')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provider_status')
                    ->label('Provider Status')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provider_reference')
                    ->label('Provider Reference')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('provider_accepted_at')
                    ->label('Provider Accepted At')
                    ->dateTime('M j, Y g:i A')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Queued At')
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
                        'appointment_request_submitted' => 'Appointment Request Submitted',
                        'appointment_scheduled' => 'Appointment Scheduled',
                        'appointment_confirmed' => 'Appointment Confirmed',
                        'appointment_rescheduled' => 'Appointment Rescheduled',
                        'appointment_cancelled' => 'Appointment Cancelled',
                        'appointment_reminder' => 'Appointment Reminder',
                        'optical_order_confirmed' => 'Optical Order Confirmed',
                        'optical_order_ready' => 'Optical Order Ready',
                        'optical_order_cancelled' => 'Optical Order Cancelled',
                        'optical_order_released' => 'Optical Order Released',
                    ]),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (SmsNotification $record): bool => $record->status?->name === 'failed')
                    ->modalDescription(fn (SmsNotification $record): string => $record->delivery_state === 'unknown'
                        ? 'TextBee may already have sent this message. Retrying could send a duplicate.'
                        : 'This will queue the failed SMS for another delivery attempt.')
                    ->requiresConfirmation()
                    ->action(function (SmsNotification $record): void {
                        $queuedStatus = NotificationStatus::query()->where('name', 'queued')->firstOrFail();
                        $updates = [
                            'notification_status_id' => $queuedStatus->id,
                            'failure_reason' => null,
                            'provider_name' => null,
                            'provider_reference' => null,
                            'provider_message_id' => null,
                            'provider_status' => null,
                            'provider_accepted_at' => null,
                        ];

                        if ($record->provider_name === 'textbee' || $record->delivery_state !== null) {
                            $updates += [
                                'delivery_state' => 'queued',
                                'send_attempted_at' => null,
                                'provider_status_updated_at' => null,
                                'provider_status_checked_at' => null,
                                'provider_last_event_id' => null,
                            ];
                        }

                        $record->update($updates);
                        SendSmsJob::dispatch($record->fresh());
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
                            $skippedUnknown = 0;

                            foreach ($records as $record) {
                                if ($record->status?->name !== 'failed') {
                                    continue;
                                }

                                if ($record->delivery_state === 'unknown') {
                                    $skippedUnknown++;

                                    continue;
                                }

                                $updates = [
                                    'notification_status_id' => $queuedStatus->id,
                                    'failure_reason' => null,
                                    'provider_name' => null,
                                    'provider_reference' => null,
                                    'provider_message_id' => null,
                                    'provider_status' => null,
                                    'provider_accepted_at' => null,
                                ];

                                if ($record->provider_name === 'textbee' || $record->delivery_state !== null) {
                                    $updates += [
                                        'delivery_state' => 'queued',
                                        'send_attempted_at' => null,
                                        'provider_status_updated_at' => null,
                                        'provider_status_checked_at' => null,
                                        'provider_last_event_id' => null,
                                    ];
                                }

                                $record->update($updates);
                                SendSmsJob::dispatch($record->fresh());
                                $count++;
                            }

                            $notification = Notification::make()
                                ->title("{$count} SMS queued for retry")
                                ->success();

                            if ($skippedUnknown > 0) {
                                $notification->body("{$skippedUnknown} with unknown delivery outcome were skipped to prevent accidental duplicates.");
                            }

                            $notification->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
