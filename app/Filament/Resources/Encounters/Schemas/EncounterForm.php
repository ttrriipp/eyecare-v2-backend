<?php

namespace App\Filament\Resources\Encounters\Schemas;

use App\Enums\EncounterStatus;
use App\Enums\IntakeStatus;
use App\Models\Encounter;
use App\Models\User;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class EncounterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)
                ->schema([
                    Grid::make(1)
                        ->schema([
                            Section::make('Encounter Information')
                                ->schema([
                                    Placeholder::make('planned_encounter_number')
                                        ->label('Encounter #')
                                        ->content(fn (Encounter $record): string => $record->encounter_number),
                                    Placeholder::make('planned_status')
                                        ->label('Status')
                                        ->content(fn (Encounter $record): HtmlString => self::badge(
                                            Str::headline($record->status->value),
                                            match ($record->status) {
                                                EncounterStatus::Planned => 'gray',
                                                EncounterStatus::InProgress => 'warning',
                                                EncounterStatus::Completed => 'success',
                                                EncounterStatus::Cancelled => 'danger',
                                            },
                                        )),
                                    Placeholder::make('planned_appointment_type')
                                        ->label('Appointment Type')
                                        ->content(fn (Encounter $record): string => $record->appointment?->appointmentType?->name ?? '—'),
                                    Placeholder::make('planned_optometrist')
                                        ->label('Optometrist')
                                        ->content(fn (Encounter $record): string => $record->optometrist?->name ?? 'Not assigned'),
                                    Placeholder::make('planned_referring_source')
                                        ->label('Referring Source')
                                        ->content(fn (Encounter $record): string => $record->appointment?->referring_source ?? '—')
                                        ->columnSpanFull()
                                        ->visible(fn (Encounter $record): bool => $record->appointment?->appointmentType?->requires_referral === true),
                                ])
                                ->columns(2),

                            Section::make('Patient Information')
                                ->schema([
                                    Placeholder::make('planned_patient_name')
                                        ->label('Patient')
                                        ->content(fn (Encounter $record): string => $record->intake?->full_name ?? $record->patient?->full_name ?? '—'),
                                    Placeholder::make('planned_patient_phone')
                                        ->label('Phone')
                                        ->content(fn (Encounter $record): string => $record->intake?->phone ?? $record->patient?->phone ?? '—'),
                                    Placeholder::make('planned_patient_dob')
                                        ->label('Date of Birth')
                                        ->content(fn (Encounter $record): string => $record->intake?->date_of_birth?->format('M d, Y') ?? $record->patient?->date_of_birth?->format('M d, Y') ?? '—'),
                                    Placeholder::make('planned_patient_gender')
                                        ->label('Gender')
                                        ->content(fn (Encounter $record): string => Str::headline($record->intake?->gender ?? $record->patient?->gender ?? '—')),
                                    Placeholder::make('planned_patient_occupation')
                                        ->label('Occupation')
                                        ->content(fn (Encounter $record): string => $record->intake?->occupation ?? $record->patient?->occupation ?? '—'),
                                    Placeholder::make('planned_patient_address')
                                        ->label('Address')
                                        ->content(fn (Encounter $record): string => $record->intake?->address ?? $record->patient?->address ?? '—'),
                                ])
                                ->columns(2),

                            Section::make('Clinical Context')
                                ->schema([
                                    Placeholder::make('planned_chief_complaint')
                                        ->label('Chief Complaint')
                                        ->content(fn (Encounter $record): string => $record->intake?->chief_complaint ?? '—')
                                        ->columnSpanFull(),
                                    Placeholder::make('planned_past_ocular_history')
                                        ->label('Past Ocular History')
                                        ->content(fn (Encounter $record): string => $record->intake?->past_ocular_history ?? '—'),
                                    Placeholder::make('planned_past_surgical_history')
                                        ->label('Past Surgical History')
                                        ->content(fn (Encounter $record): string => $record->intake?->past_surgical_history ?? '—'),
                                    Placeholder::make('planned_past_medical_history')
                                        ->label('Past Medical History')
                                        ->content(fn (Encounter $record): string => $record->intake?->past_medical_history ?? '—'),
                                    Placeholder::make('planned_allergies')
                                        ->label('Allergies')
                                        ->content(fn (Encounter $record): string => $record->intake?->allergies ?? '—'),
                                    Placeholder::make('planned_medications')
                                        ->label('Medications')
                                        ->content(fn (Encounter $record): string => $record->intake?->medications ?? '—'),
                                ])
                                ->columns(2),
                        ])
                        ->columnSpan(2),

                    Section::make('Visit Logistics')
                        ->schema([
                            Placeholder::make('planned_appointment_number')
                                ->label('Appointment #')
                                ->content(fn (Encounter $record): string => $record->appointment?->appointment_number ?? '—'),
                            Placeholder::make('planned_visit_type')
                                ->label('Visit Type')
                                ->content(fn (Encounter $record): string => $record->appointment?->source === 'walk_in' ? 'Walk-in' : 'Scheduled'),
                            Placeholder::make('planned_scheduled_at')
                                ->label('Scheduled Time')
                                ->content(fn (Encounter $record): string => $record->appointment?->scheduled_at?->format('M d, Y g:i A') ?? '—'),
                            Placeholder::make('planned_checked_in_at')
                                ->label('Check-in Time')
                                ->content(fn (Encounter $record): string => $record->appointment?->checked_in_at?->format('g:i A') ?? '—'),
                            Placeholder::make('planned_waiting_duration')
                                ->label('Waiting Time')
                                ->content(function (Encounter $record): string {
                                    $checkedIn = $record->appointment?->checked_in_at;
                                    if ($checkedIn === null) {
                                        return '—';
                                    }

                                    return $checkedIn->diffForHumans(['parts' => 2, 'short' => true]);
                                }),
                            Placeholder::make('planned_intake_status')
                                ->label('Health Record Status')
                                ->content(function (Encounter $record): HtmlString {
                                    $intake = $record->intake;
                                    if ($intake === null) {
                                        return self::badge('Not Started', 'gray');
                                    }

                                    [$label, $color] = match ($intake->status) {
                                        IntakeStatus::Draft => ['Incomplete', 'warning'],
                                        IntakeStatus::Submitted => ['Awaiting Review', 'info'],
                                        IntakeStatus::Verified => ['Reviewed', 'success'],
                                        default => ['Not Started', 'gray'],
                                    };

                                    return self::badge($label, $color);
                                }),
                        ])
                        ->columnSpan(1),
                ])
                ->visible(fn (Encounter $record): bool => $record->status === EncounterStatus::Planned),

            // ── Encounter Details (in_progress / completed) ──────
            Section::make('Encounter Details')->columns(2)->schema([
                TextInput::make('encounter_number')
                    ->label('Encounter #')
                    ->disabled()
                    ->dehydrated(false),
                Select::make('status')
                    ->options(EncounterStatus::class)
                    ->disabled()
                    ->dehydrated(false),
                Select::make('optometrist_id')
                    ->label('Optometrist')
                    ->relationship('optometrist', 'name')
                    ->options(fn () => User::query()->optometrists()->orderBy('name')->pluck('name', 'id'))
                    ->nullable()
                    ->searchable()
                    ->preload(),
            ])
                ->visible(fn (Encounter $record): bool => $record->status !== EncounterStatus::Planned),

            Section::make('Patient Information')->columns(2)->schema([
                Placeholder::make('patient_name_info')
                    ->label('Patient')
                    ->content(fn (Encounter $record): string => $record->intake?->full_name ?? $record->patient?->full_name ?? '—'),
                Placeholder::make('patient_phone')
                    ->label('Phone')
                    ->content(fn (Encounter $record): string => $record->intake?->phone ?? $record->patient?->phone ?? '—'),
                Placeholder::make('patient_dob')
                    ->label('Date of Birth')
                    ->content(fn (Encounter $record): string => $record->intake?->date_of_birth?->format('M d, Y') ?? $record->patient?->date_of_birth?->format('M d, Y') ?? '—'),
                Placeholder::make('patient_gender')
                    ->label('Gender')
                    ->content(fn (Encounter $record): string => Str::headline($record->intake?->gender ?? $record->patient?->gender ?? '—')),
                Placeholder::make('patient_occupation')
                    ->label('Occupation')
                    ->content(fn (Encounter $record): string => $record->intake?->occupation ?? $record->patient?->occupation ?? '—'),
                Placeholder::make('patient_address')
                    ->label('Address')
                    ->content(fn (Encounter $record): string => $record->intake?->address ?? $record->patient?->address ?? '—'),
                Placeholder::make('appointment_date_info')
                    ->label('Appointment')
                    ->content(fn (Encounter $record): string => $record->appointment?->scheduled_at?->format('M d, Y g:i A') ?? '—'),
                Placeholder::make('appointment_type_info')
                    ->label('Appointment Type')
                    ->content(fn (Encounter $record): string => $record->appointment?->appointmentType?->name ?? '—'),
            ])
                ->visible(fn (Encounter $record): bool => $record->status !== EncounterStatus::Planned),

            Section::make('Patient Health Record')->schema([
                Placeholder::make('intake.chief_complaint_display')
                    ->label('Chief Complaint')
                    ->content(fn (Encounter $record): string => $record->intake?->chief_complaint ?? '—')
                    ->columnSpanFull(),
                Placeholder::make('intake.past_ocular_history_display')
                    ->label('Past Ocular History')
                    ->content(fn (Encounter $record): string => $record->intake?->past_ocular_history ?? '—'),
                Placeholder::make('intake.past_surgical_history_display')
                    ->label('Past Surgical History')
                    ->content(fn (Encounter $record): string => $record->intake?->past_surgical_history ?? '—'),
                Placeholder::make('intake.past_medical_history_display')
                    ->label('Past Medical History')
                    ->content(fn (Encounter $record): string => $record->intake?->past_medical_history ?? '—'),
                Placeholder::make('intake.allergies_display')
                    ->label('Allergies')
                    ->content(fn (Encounter $record): string => $record->intake?->allergies ?? '—'),
                Placeholder::make('intake.medications_display')
                    ->label('Medications')
                    ->content(fn (Encounter $record): string => $record->intake?->medications ?? '—'),
            ])
                ->visible(fn (Encounter $record): bool => $record->status !== EncounterStatus::Planned),

            // ── Clinical Findings (in_progress / completed only) ─
            Section::make('Clinical Findings')
                ->schema([
                    Textarea::make('findings')
                        ->rows(4)
                        ->nullable(),
                    Textarea::make('remarks')
                        ->rows(3)
                        ->nullable(),
                ])
                ->visible(fn (Encounter $record): bool => in_array($record->status, [EncounterStatus::InProgress, EncounterStatus::Completed], true)),
        ]);
    }

    private static function badge(string $label, string $color): HtmlString
    {
        return new HtmlString(Blade::render('<x-filament::badge :color="$color">{{ $label }}</x-filament::badge>', [
            'color' => $color,
            'label' => $label,
        ]));
    }
}
