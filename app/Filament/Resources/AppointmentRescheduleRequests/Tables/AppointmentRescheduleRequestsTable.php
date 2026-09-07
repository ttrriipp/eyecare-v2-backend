<?php

namespace App\Filament\Resources\AppointmentRescheduleRequests\Tables;

use App\Enums\AppointmentRescheduleRequestStatus;
use App\Enums\AppointmentStatusName;
use App\Filament\Resources\AppointmentRescheduleRequests\AppointmentRescheduleRequestResource;
use App\Models\Appointment;
use App\Models\AppointmentRescheduleRequest;
use App\Models\AppointmentStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class AppointmentRescheduleRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('request_number')
                    ->label('Request #')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('appointment.appointment_number')
                    ->label('Appointment #')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('patient.full_name')
                    ->label('Patient')
                    ->weight('bold')
                    ->searchable(['first_name', 'last_name']),

                TextColumn::make('current_scheduled_at')
                    ->label('Original Time')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),

                TextColumn::make('requested_scheduled_at')
                    ->label('Preferred Time')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),

                TextColumn::make('status')
                    ->state(fn (AppointmentRescheduleRequest $record): AppointmentRescheduleRequestStatus => $record->effectiveStatus())
                    ->badge()
                    ->color(fn (AppointmentRescheduleRequestStatus $state): string => match ($state) {
                        AppointmentRescheduleRequestStatus::Pending => 'warning',
                        AppointmentRescheduleRequestStatus::Approved => 'success',
                        AppointmentRescheduleRequestStatus::Rejected => 'danger',
                        AppointmentRescheduleRequestStatus::Cancelled,
                        AppointmentRescheduleRequestStatus::Expired => 'gray',
                    })
                    ->formatStateUsing(fn (AppointmentRescheduleRequestStatus $state): string => Str::headline($state->value)),
            ])
            ->defaultSort(fn (Builder $query): Builder => self::effectivePendingOrder($query))
            ->filters([
                SelectFilter::make('status')
                    ->options(AppointmentRescheduleRequestStatus::class)
                    ->query(function (Builder $query, array $data): void {
                        $status = $data['value'] ?? null;

                        if (blank($status)) {
                            return;
                        }

                        if ($status === AppointmentRescheduleRequestStatus::Pending->value) {
                            AppointmentRescheduleRequestResource::whereEffectivePending($query);

                            return;
                        }

                        if ($status === AppointmentRescheduleRequestStatus::Expired->value) {
                            AppointmentRescheduleRequestResource::whereEffectiveExpired($query);

                            return;
                        }

                        $query->where('status', $status);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }

    private static function effectivePendingOrder(Builder $query): Builder
    {
        $requestTable = (new AppointmentRescheduleRequest)->getTable();
        $appointmentTable = (new Appointment)->getTable();
        $statusTable = (new AppointmentStatus)->getTable();

        return $query
            ->orderByRaw(
                "CASE WHEN {$requestTable}.status = ? AND {$requestTable}.expires_at > ? AND EXISTS (
                    SELECT 1
                    FROM {$appointmentTable}
                    INNER JOIN {$statusTable}
                        ON {$statusTable}.id = {$appointmentTable}.appointment_status_id
                    WHERE {$appointmentTable}.id = {$requestTable}.appointment_id
                        AND {$statusTable}.name = ?
                        AND {$appointmentTable}.scheduled_at > ?
                        AND {$appointmentTable}.scheduled_at = {$requestTable}.current_scheduled_at
                ) THEN 0 ELSE 1 END",
                [
                    AppointmentRescheduleRequestStatus::Pending->value,
                    now(),
                    AppointmentStatusName::Scheduled->value,
                    now(),
                ],
            )
            ->orderBy('expires_at')
            ->orderByDesc('created_at');
    }
}
