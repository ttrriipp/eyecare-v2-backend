<?php

namespace App\Filament\Resources\Appointments\Tables;

use App\Actions\Appointments\RescheduleAppointment;
use App\Actions\Appointments\UpdateAppointmentStatus;
use App\Filament\Resources\Appointments\Support\AppointmentTime;
use App\Models\Appointment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AppointmentsTable
{
    public static function configure(Table $table): Table
    {
        $advanceLabels = [
            'pending' => ['label' => 'Confirm',  'icon' => 'heroicon-o-check-circle', 'color' => 'success', 'next' => 'confirmed'],
            'confirmed' => ['label' => 'Mark Arrived', 'icon' => 'heroicon-o-user', 'color' => 'warning', 'next' => 'arrived'],
            'arrived' => ['label' => 'Complete', 'icon' => 'heroicon-o-check-badge', 'color' => 'success', 'next' => 'completed'],
        ];

        return $table
            ->columns([
                TextColumn::make('appointment_number')
                    ->label('Number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('Patient')
                    ->searchable(),
                TextColumn::make('visitReason.name')
                    ->label('Visit reason'),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (Appointment $record): string => match ($record->status?->name) {
                        'pending' => 'gray',
                        'confirmed' => 'info',
                        'arrived' => 'warning',
                        'completed' => 'success',
                        'no_show' => 'gray',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                TextColumn::make('optometrist.name')
                    ->label('Optometrist')
                    ->placeholder('Unassigned')
                    ->sortable(),
                TextColumn::make('createdBy.name')
                    ->label('Booked by')
                    ->placeholder('System / patient')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'mobile_app' => 'Mobile app',
                        'walk_in' => 'Walk-in',
                        'phone_call' => 'Phone call',
                        'messenger' => 'Messenger',
                        default => 'In person',
                    })
                    ->toggleable(),
                TextColumn::make('scheduled_at')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                TextColumn::make('contact_notes')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('walk_in_queue')
                    ->label("Today's walk-ins")
                    ->query(fn (Builder $query): Builder => $query
                        ->where('source', 'walk_in')
                        ->whereDate('scheduled_at', today())
                        ->whereHas('status', fn (Builder $statusQuery): Builder => $statusQuery->where('name', 'arrived')))
                    ->toggle(),
                SelectFilter::make('optometrist')
                    ->relationship('optometrist', 'name', fn (Builder $query): Builder => $query->optometrists())
                    ->searchable()
                    ->preload(),
                Filter::make('scheduled_date')
                    ->schema([
                        DatePicker::make('scheduled_on')
                            ->label('Scheduled date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['scheduled_on'] ?? null,
                            fn (Builder $query, string $date): Builder => $query->whereDate('scheduled_at', $date),
                        );
                    }),
                TrashedFilter::make()
                    ->label('Show Archived')
                    ->placeholder('Active only')
                    ->trueLabel('Active and archived')
                    ->falseLabel('Archived only'),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->color('gray'),
                    Action::make('advance')
                        ->label(fn (Appointment $record): string => $advanceLabels[$record->status?->name]['label'] ?? '')
                        ->icon(fn (Appointment $record): string => $advanceLabels[$record->status?->name]['icon'] ?? 'heroicon-o-arrow-right')
                        ->color(fn (Appointment $record): string => $advanceLabels[$record->status?->name]['color'] ?? 'gray')
                        ->visible(fn (Appointment $record): bool => isset($advanceLabels[$record->status?->name]))
                        ->requiresConfirmation()
                        ->action(function (Appointment $record) use ($advanceLabels): void {
                            $next = $advanceLabels[$record->status->name]['next'] ?? null;
                            if (! $next) {
                                return;
                            }
                            try {
                                app(UpdateAppointmentStatus::class)->handle($record, $next);
                                Notification::make()->title('Appointment status updated')->success()->send();
                            } catch (ValidationException $e) {
                                $message = collect($e->errors())->flatten()->first() ?? 'Cannot advance appointment.';
                                Notification::make()->title('Cannot advance appointment')->body($message)->danger()->send();
                            }
                        }),
                    Action::make('reschedule')
                        ->label('Reschedule')
                        ->icon('heroicon-o-calendar-days')
                        ->color('info')
                        ->visible(fn (Appointment $record): bool => in_array($record->status?->name, ['pending', 'confirmed'], true))
                        ->schema([
                            DatePicker::make('scheduled_at')
                                ->label('New appointment date')
                                ->required()
                                ->native(false)
                                ->displayFormat('M d, Y')
                                ->placeholder('Choose a new appointment date')
                                ->suffixIcon('heroicon-o-calendar-days')
                                ->minDate(today())
                                ->afterOrEqual('today'),
                            TimePicker::make('appointment_time')
                                ->label('New appointment time')
                                ->required()
                                ->seconds(false)
                                ->minutesStep(1)
                                ->format('H:i'),
                            Textarea::make('reschedule_reason')
                                ->label('Reason')
                                ->required()
                                ->maxLength(1000)
                                ->columnSpanFull(),
                        ])
                        ->action(function (Appointment $record, array $data): void {
                            try {
                                app(RescheduleAppointment::class)->handle(
                                    appointment: $record,
                                    scheduledAt: AppointmentTime::combine(
                                        $data['scheduled_at'],
                                        $data['appointment_time'],
                                    ),
                                    customerInitiated: false,
                                    rescheduleReason: $data['reschedule_reason'],
                                );
                                Notification::make()->title('Appointment rescheduled')->success()->send();
                            } catch (ValidationException $e) {
                                $message = collect($e->errors())->flatten()->first() ?? 'Cannot reschedule appointment.';
                                Notification::make()->title('Cannot reschedule')->body($message)->danger()->send();
                            }
                        }),
                    Action::make('noShow')
                        ->label('Mark No-show')
                        ->icon('heroicon-o-user-minus')
                        ->color('warning')
                        ->visible(fn (Appointment $record): bool => $record->status?->name === 'confirmed')
                        ->requiresConfirmation()
                        ->action(function (Appointment $record): void {
                            app(UpdateAppointmentStatus::class)->handle($record, 'no_show');
                            Notification::make()->title('Appointment marked as no-show')->success()->send();
                        }),
                    Action::make('cancel')
                        ->label('Cancel Appointment')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (Appointment $record): bool => in_array($record->status?->name, ['pending', 'confirmed', 'arrived'], true))
                        ->requiresConfirmation()
                        ->action(function (Appointment $record): void {
                            try {
                                app(UpdateAppointmentStatus::class)->handle($record, 'cancelled');
                                Notification::make()->title('Appointment cancelled')->success()->send();
                            } catch (ValidationException $e) {
                                $message = collect($e->errors())->flatten()->first() ?? 'Cannot cancel appointment.';
                                Notification::make()->title('Cannot cancel appointment')->body($message)->danger()->send();
                            }
                        }),
                    RestoreAction::make()->label('Restore')->color('success')->visible(fn (Appointment $record): bool => (auth()->user()?->isAdmin() ?? false) && $record->trashed()),
                    DeleteAction::make()->label('Archive')->color('gray')->icon('heroicon-o-archive-box')->modalIcon('heroicon-o-archive-box')->modalHeading('Archive appointment')->modalDescription('This will hide the appointment from active lists. It can be restored later from the "Show Archived" filter.')->modalSubmitActionLabel('Archive')->visible(fn (Appointment $record): bool => (auth()->user()?->isAdmin() ?? false) && ! $record->trashed()),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_confirm')
                        ->label('Confirm Selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $advanced = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if ($record->status?->name !== 'pending') {
                                    $skipped++;

                                    continue;
                                }

                                try {
                                    app(UpdateAppointmentStatus::class)->handle($record, 'confirmed');
                                    $advanced++;
                                } catch (\Throwable) {
                                    $skipped++;
                                }
                            }

                            Notification::make()
                                ->title("{$advanced} appointment(s) confirmed".($skipped > 0 ? ", {$skipped} skipped" : ''))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('bulk_cancel')
                        ->label('Cancel Selected')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                        ->action(function (Collection $records): void {
                            $cancelled = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if (! in_array($record->status?->name, ['pending', 'confirmed', 'arrived'], true)) {
                                    $skipped++;

                                    continue;
                                }

                                try {
                                    app(UpdateAppointmentStatus::class)->handle($record, 'cancelled');
                                    $cancelled++;
                                } catch (\Throwable) {
                                    $skipped++;
                                }
                            }

                            Notification::make()
                                ->title("{$cancelled} appointment(s) cancelled".($skipped > 0 ? ", {$skipped} skipped" : ''))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('scheduled_at', 'desc');
    }
}
