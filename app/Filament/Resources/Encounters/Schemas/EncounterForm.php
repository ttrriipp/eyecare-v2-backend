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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class EncounterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            // ── Waiting Queue Section (planned only) ─────────────
            Section::make('Waiting to be seen')
                ->schema([
                    Placeholder::make('patient_name')
                        ->label('Patient')
                        ->content(fn (Encounter $record): string => $record->intake?->full_name ?? $record->patient?->full_name ?? '—'),
                    Placeholder::make('appointment_number')
                        ->label('Appointment #')
                        ->content(fn (Encounter $record): string => $record->appointment?->appointment_number ?? '—'),
                    Placeholder::make('appointment_type')
                        ->label('Appointment Type')
                        ->content(fn (Encounter $record): string => $record->appointment?->appointmentType?->name ?? '—'),
                    Placeholder::make('scheduled_at')
                        ->label('Scheduled Time')
                        ->content(fn (Encounter $record): string => $record->appointment?->scheduled_at?->format('M d, Y g:i A') ?? '—'),
                    Placeholder::make('checked_in_at')
                        ->label('Check-in Time')
                        ->content(fn (Encounter $record): string => $record->appointment?->checked_in_at?->format('g:i A') ?? '—'),
                    Placeholder::make('waiting_duration')
                        ->label('Waiting Duration')
                        ->content(function (Encounter $record): string {
                            $checkedIn = $record->appointment?->checked_in_at;
                            if ($checkedIn === null) {
                                return '—';
                            }

                            return $checkedIn->diffForHumans(['parts' => 2, 'short' => true]);
                        }),
                    Placeholder::make('optometrist_display')
                        ->label('Optometrist')
                        ->content(fn (Encounter $record): string => $record->optometrist?->name ?? 'Not assigned'),
                    Placeholder::make('intake_status')
                        ->label('Health Record Status')
                        ->content(function (Encounter $record): string {
                            $intake = $record->intake;
                            if ($intake === null) {
                                return 'Not started';
                            }

                            return match ($intake->status) {
                                IntakeStatus::Draft => 'Incomplete',
                                IntakeStatus::Submitted => 'Needs review',
                                IntakeStatus::Verified => 'Verified',
                                default => 'Not started',
                            };
                        }),
                    Placeholder::make('chief_complaint')
                        ->label('Chief Complaint')
                        ->content(fn (Encounter $record): string => $record->intake?->chief_complaint ?? '—')
                        ->columnSpanFull(),
                    Placeholder::make('allergy_warnings')
                        ->label('Allergies')
                        ->content(function (Encounter $record): string {
                            $allergies = $record->intake?->allergies;
                            if (blank($allergies)) {
                                return 'None reported';
                            }

                            return '⚠ '.$allergies;
                        }),
                    Placeholder::make('medication_warnings')
                        ->label('Medications')
                        ->content(function (Encounter $record): string {
                            $medications = $record->intake?->medications;
                            if (blank($medications)) {
                                return 'None reported';
                            }

                            return '⚠ '.$medications;
                        }),
                    Placeholder::make('walk_in_indicator')
                        ->label('Visit Type')
                        ->content(fn (Encounter $record): string => $record->appointment?->source === 'walk_in' ? 'Walk-in' : 'Scheduled'),
                ])
                ->columns(2)
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

            // ── Patient Information (always visible) ─────────────
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
            ]),

            // ── Patient Health Record (always visible, read-only) ─
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
            ]),

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
}
