<?php

namespace App\Filament\Resources\Appointments\Tables;

use App\Actions\Appointments\UpdateAppointmentStatus;
use App\Models\Appointment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AppointmentsTable
{
    public static function configure(Table $table): Table
    {
        $advanceLabels = [
            'pending' => ['label' => 'Confirm',  'icon' => 'heroicon-o-check-circle', 'color' => 'success', 'next' => 'confirmed'],
            'confirmed' => ['label' => 'Complete', 'icon' => 'heroicon-o-check-badge',  'color' => 'success', 'next' => 'completed'],
            'rescheduled' => ['label' => 'Confirm',  'icon' => 'heroicon-o-check-circle', 'color' => 'success', 'next' => 'confirmed'],
        ];

        return $table
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('visitReason.name')
                    ->label('Visit reason'),
                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (Appointment $record): string => match ($record->status?->name) {
                        'pending' => 'gray',
                        'confirmed' => 'info',
                        'rescheduled' => 'warning',
                        'completed' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('staff.name')
                    ->label('Assigned staff')
                    ->placeholder('Unassigned')
                    ->toggleable(),
                TextColumn::make('scheduled_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('contact_notes')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('assigned_to_me')
                    ->label('Assigned to me')
                    ->query(fn (Builder $query): Builder => $query->where('staff_id', Auth::id()))
                    ->toggle(),
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
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
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
                        ->color('warning')
                        ->visible(fn (Appointment $record): bool => in_array($record->status?->name, ['pending', 'confirmed', 'rescheduled'], true))
                        ->schema([
                            DateTimePicker::make('scheduled_at')
                                ->label('New date & time')
                                ->required()
                                ->native(false)
                                ->seconds(false)
                                ->minutesStep(15)
                                ->displayFormat('M d, Y h:i A')
                                ->prefixIcon('heroicon-o-calendar-days')
                                ->minDate(now())
                                ->after('now'),
                        ])
                        ->action(function (Appointment $record, array $data): void {
                            try {
                                app(UpdateAppointmentStatus::class)->handle(
                                    appointment: $record,
                                    statusName: 'rescheduled',
                                    scheduledAt: Carbon::parse($data['scheduled_at']),
                                );
                                Notification::make()->title('Appointment rescheduled')->success()->send();
                            } catch (ValidationException $e) {
                                $message = collect($e->errors())->flatten()->first() ?? 'Cannot reschedule appointment.';
                                Notification::make()->title('Cannot reschedule')->body($message)->danger()->send();
                            }
                        }),
                    Action::make('cancel')
                        ->label('Cancel Appointment')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (Appointment $record): bool => ! in_array($record->status?->name, ['completed', 'cancelled'], true))
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
                    RestoreAction::make()->visible(fn (Appointment $record): bool => (auth()->user()?->isAdmin() ?? false) && $record->trashed()),
                    DeleteAction::make()->visible(fn (Appointment $record): bool => (auth()->user()?->isAdmin() ?? false) && ! $record->trashed()),
                    ForceDeleteAction::make()->visible(fn (Appointment $record): bool => (auth()->user()?->isAdmin() ?? false) && $record->trashed()),
                ]),
            ])
            ->defaultSort('scheduled_at', 'desc');
    }
}
