<?php

namespace App\Filament\Resources\Appointments\Tables;

use App\Actions\Appointments\UpdateAppointmentStatus;
use App\Models\Appointment;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AppointmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('visitReason.name')
                    ->label('Visit reason'),
                TextColumn::make('status.name')
                    ->label('Status'),
                TextColumn::make('scheduled_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('contact_notes')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->relationship('status', 'name'),
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
            ])
            ->recordActions([
                Action::make('confirm')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Confirm this appointment? An SMS notification will be sent.')
                    ->successNotificationTitle('Appointment confirmed')
                    ->visible(fn (Appointment $record): bool => in_array($record->status->name, ['pending', 'rescheduled'], true))
                    ->action(fn (Appointment $record) => app(UpdateAppointmentStatus::class)->handle(
                        appointment: $record,
                        statusName: 'confirmed',
                    )),

                Action::make('reschedule')
                    ->icon('heroicon-o-calendar')
                    ->color('warning')
                    ->fillForm(fn (Appointment $record): array => [
                        'scheduled_at' => $record->scheduled_at,
                    ])
                    ->schema([
                        DateTimePicker::make('scheduled_at')
                            ->label('New date & time')
                            ->required(),
                        Textarea::make('staff_notes')
                            ->label('Staff notes')
                            ->rows(2),
                    ])
                    ->successNotificationTitle('Appointment rescheduled')
                    ->visible(fn (Appointment $record): bool => in_array($record->status->name, ['pending', 'confirmed'], true))
                    ->action(fn (array $data, Appointment $record) => app(UpdateAppointmentStatus::class)->handle(
                        appointment: $record,
                        statusName: 'rescheduled',
                        scheduledAt: Carbon::parse($data['scheduled_at']),
                        staffNotes: $data['staff_notes'] ?? null,
                    )),

                Action::make('cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Cancel this appointment? An SMS notification will be sent.')
                    ->successNotificationTitle('Appointment cancelled')
                    ->visible(fn (Appointment $record): bool => in_array($record->status->name, ['pending', 'confirmed', 'rescheduled'], true))
                    ->action(fn (Appointment $record) => app(UpdateAppointmentStatus::class)->handle(
                        appointment: $record,
                        statusName: 'cancelled',
                    )),

                Action::make('complete')
                    ->icon('heroicon-o-check-badge')
                    ->color('info')
                    ->requiresConfirmation()
                    ->successNotificationTitle('Appointment completed')
                    ->visible(fn (Appointment $record): bool => in_array($record->status->name, ['confirmed', 'rescheduled'], true))
                    ->action(fn (Appointment $record) => app(UpdateAppointmentStatus::class)->handle(
                        appointment: $record,
                        statusName: 'completed',
                    )),

                EditAction::make(),
            ])
            ->defaultSort('scheduled_at', 'desc');
    }
}
