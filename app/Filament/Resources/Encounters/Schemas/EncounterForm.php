<?php

namespace App\Filament\Resources\Encounters\Schemas;

use App\Models\Encounter;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class EncounterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            // Details section
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
                                        ->content(fn (Encounter $record): string => Str::headline($record->status->value)),
                                    Placeholder::make('planned_appointment_type')
                                        ->label('Appointment Type')
                                        ->content(fn (Encounter $record): string => $record->appointment?->appointmentType?->name ?? '—'),
                                    Placeholder::make('planned_optometrist')
                                        ->label('Optometrist')
                                        ->content(fn (Encounter $record): string => $record->optometrist?->name ?? 'Not assigned'),
                                ])
                                ->columns(2),

                            Section::make('Patient Information')
                                ->schema([
                                    Placeholder::make('planned_patient_name')
                                        ->label('Patient')
                                        ->content(fn (Encounter $record): string => $record->patient?->full_name ?? '—'),
                                    Placeholder::make('planned_patient_phone')
                                        ->label('Phone')
                                        ->content(fn (Encounter $record): string => $record->patient?->phone ?? '—'),
                                    Placeholder::make('planned_patient_dob')
                                        ->label('Date of Birth')
                                        ->content(fn (Encounter $record): string => $record->patient?->date_of_birth?->format('M d, Y') ?? '—'),
                                ])
                                ->columns(3),
                        ])
                        ->columnSpan(2),

                    Grid::make(1)
                        ->schema([
                            Section::make('Timeline')
                                ->schema([
                                    Placeholder::make('started_at')
                                        ->label('Started')
                                        ->content(fn (Encounter $record): string => $record->started_at?->format('M j, Y g:i A') ?? '—'),
                                    Placeholder::make('completed_at')
                                        ->label('Completed')
                                        ->content(fn (Encounter $record): string => $record->completed_at?->format('M j, Y g:i A') ?? '—'),
                                ]),
                        ]),
                ]),

            // Consultation wizard section
            Section::make('Consultation')
                ->schema([
                    Wizard::make([
                        Step::make('Consultation & History')
                            ->description('Chief complaint and medical history')
                            ->schema([
                                Textarea::make('chief_complaint')
                                    ->label('Chief Complaint')
                                    ->rows(3)
                                    ->columnSpanFull(),
                                Textarea::make('past_ocular_history')
                                    ->label('Past Ocular History')
                                    ->rows(2),
                                Textarea::make('past_surgical_history')
                                    ->label('Past Surgical History')
                                    ->rows(2),
                                Textarea::make('past_medical_history')
                                    ->label('Past Medical History')
                                    ->rows(2),
                                Textarea::make('allergies')
                                    ->label('Allergies')
                                    ->rows(2),
                                Textarea::make('medications')
                                    ->label('Current Medications')
                                    ->rows(2),
                            ])
                            ->columns(2),

                        Step::make('Examination')
                            ->description('Clinical findings')
                            ->schema([
                                Textarea::make('findings')
                                    ->label('Examination Findings')
                                    ->rows(6)
                                    ->columnSpanFull(),
                                Textarea::make('remarks')
                                    ->label('Remarks')
                                    ->rows(4)
                                    ->columnSpanFull(),
                            ]),

                        Step::make('Prescription & Plan')
                            ->description('Treatment plan')
                            ->schema([
                                Textarea::make('plan')
                                    ->label('Visit Plan')
                                    ->rows(4)
                                    ->columnSpanFull(),
                            ]),

                        Step::make('Review & Complete')
                            ->description('Summary and completion')
                            ->schema([
                                Placeholder::make('summary_chief_complaint')
                                    ->label('Chief Complaint')
                                    ->content(fn (Encounter $record): string => $record->chief_complaint ?? '—'),
                                Placeholder::make('summary_findings')
                                    ->label('Findings')
                                    ->content(fn (Encounter $record): string => $record->findings ?? '—'),
                                Placeholder::make('summary_plan')
                                    ->label('Plan')
                                    ->content(fn (Encounter $record): string => $record->plan ?? '—'),
                            ])
                            ->columns(2),
                    ])
                        ->submitAction(null),
                ])
                ->columnSpanFull(),
        ]);
    }
}
