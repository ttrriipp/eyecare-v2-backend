<?php

namespace App\Filament\Resources\AppointmentRescheduleRequests\Schemas;

use App\Actions\Appointments\EvaluateAppointmentAvailability;
use App\Enums\AppointmentRescheduleRequestStatus;
use App\Models\AppointmentRescheduleRequest;
use Carbon\CarbonInterface;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AppointmentRescheduleRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request details')
                    ->schema([
                        TextEntry::make('request_number')
                            ->label('Request #'),

                        TextEntry::make('status')
                            ->label('Status')
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

                        TextEntry::make('created_at')
                            ->label('Submitted')
                            ->dateTime('M j, Y g:i A'),

                        TextEntry::make('expires_at')
                            ->label('Request expiry')
                            ->dateTime('M j, Y g:i A'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Patient and appointment')
                    ->schema([
                        TextEntry::make('patient.full_name')
                            ->label('Patient')
                            ->weight('bold')
                            ->placeholder('Patient record unavailable'),

                        TextEntry::make('patient.patient_number')
                            ->label('Patient #')
                            ->placeholder('—'),

                        TextEntry::make('appointment.appointment_number')
                            ->label('Appointment #')
                            ->placeholder('Appointment unavailable'),

                        TextEntry::make('appointment.status.name')
                            ->label('Appointment status')
                            ->formatStateUsing(fn (?string $state): ?string => $state === null
                                ? null
                                : Str::headline($state))
                            ->badge()
                            ->placeholder('—'),

                        TextEntry::make('current_scheduled_at')
                            ->label('Original appointment')
                            ->dateTime('M j, Y g:i A'),

                        TextEntry::make('appointment.duration_minutes')
                            ->label('Duration')
                            ->suffix(' minutes'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Submitted choices')
                    ->schema([
                        RepeatableEntry::make('submitted_choices')
                            ->hiddenLabel()
                            ->state(fn (AppointmentRescheduleRequest $record): array => self::choiceStates($record))
                            ->table([
                                TableColumn::make('Choice'),
                                TableColumn::make('Time'),
                                TableColumn::make('Current availability'),
                            ])
                            ->schema([
                                TextEntry::make('label'),
                                TextEntry::make('scheduled_at')
                                    ->dateTime('M j, Y g:i A'),
                                TextEntry::make('availability')
                                    ->badge()
                                    ->color(fn (string $state): string => $state === 'Available' ? 'success' : 'gray'),
                            ])
                            ->contained(false),
                    ])
                    ->columnSpanFull(),

                Section::make('Resolution')
                    ->schema([
                        TextEntry::make('selected_scheduled_at')
                            ->label('Approved time')
                            ->dateTime('M j, Y g:i A')
                            ->placeholder('—'),

                        TextEntry::make('resolvedBy.full_name')
                            ->label('Resolved by')
                            ->placeholder('—'),

                        TextEntry::make('resolved_at')
                            ->label('Resolved at')
                            ->dateTime('M j, Y g:i A')
                            ->placeholder('—'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return list<array{label: string, scheduled_at: CarbonInterface, availability: string}>
     */
    private static function choiceStates(AppointmentRescheduleRequest $record): array
    {
        $appointment = $record->appointment;
        $evaluator = app(EvaluateAppointmentAvailability::class);

        return collect($record->submittedScheduledTimes())
            ->values()
            ->map(function (CarbonInterface $scheduledAt, int $index) use ($appointment, $evaluator): array {
                $decision = $evaluator->handle(
                    startsAt: $scheduledAt,
                    durationMinutes: (int) ($appointment?->duration_minutes ?? 30),
                    optometrist: $appointment?->optometrist,
                    ignoreAppointment: $appointment,
                    enforceFuture: true,
                    enforceGrid: true,
                );

                return [
                    'label' => $index === 0 ? 'Preferred' : 'Alternative '.($index),
                    'scheduled_at' => $scheduledAt,
                    'availability' => $decision->available
                        ? 'Available'
                        : self::availabilityLabel($decision->reason),
                ];
            })
            ->all();
    }

    private static function availabilityLabel(?string $reason): string
    {
        return match ($reason) {
            'clinic_closed' => 'Clinic closed',
            'outside_clinic_hours' => 'Outside clinic hours',
            'capacity_reached' => 'Unavailable',
            'elapsed' => 'Elapsed',
            'outside_slot_grid' => 'Outside available grid',
            default => 'Unavailable',
        };
    }
}
