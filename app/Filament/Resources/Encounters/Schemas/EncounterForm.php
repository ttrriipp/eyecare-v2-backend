<?php

namespace App\Filament\Resources\Encounters\Schemas;

use App\Enums\EncounterStatus;
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
            Grid::make(3)->schema([
                // ── Main (2/3): Consultation or Summary ──────────────
                Grid::make(1)->columnSpan(2)->schema([
                    // Wizard for in-progress encounters
                    Section::make('Consultation')
                        ->visible(fn (Encounter $record): bool => $record->status === EncounterStatus::InProgress)
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
                        ]),

                    // Summary for planned/completed encounters
                    Section::make('Clinical Summary')
                        ->visible(fn (Encounter $record): bool => $record->status !== EncounterStatus::InProgress)
                        ->schema([
                            Placeholder::make('view_chief_complaint')
                                ->label('Chief Complaint')
                                ->content(fn (Encounter $record): string => $record->chief_complaint ?? '—'),
                            Placeholder::make('view_findings')
                                ->label('Findings')
                                ->content(fn (Encounter $record): string => $record->findings ?? '—'),
                            Placeholder::make('view_plan')
                                ->label('Plan')
                                ->content(fn (Encounter $record): string => $record->plan ?? '—'),
                            Placeholder::make('view_remarks')
                                ->label('Remarks')
                                ->content(fn (Encounter $record): string => $record->remarks ?? '—'),
                        ])
                        ->columns(2),
                ]),

                // ── Sidebar (1/3): Encounter Details ────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make('Encounter')
                        ->schema([
                            Placeholder::make('encounter_number')
                                ->label('Encounter #')
                                ->content(fn (Encounter $record): string => $record->encounter_number),
                            Placeholder::make('status')
                                ->label('Status')
                                ->content(fn (Encounter $record): string => Str::headline($record->status->value)),
                            Placeholder::make('appointment_type')
                                ->label('Appointment Type')
                                ->content(fn (Encounter $record): string => $record->appointment?->appointmentType?->name ?? '—'),
                            Placeholder::make('optometrist')
                                ->label('Optometrist')
                                ->content(fn (Encounter $record): string => $record->optometrist?->name ?? 'Not assigned'),
                        ]),

                    Section::make('Patient')
                        ->schema([
                            Placeholder::make('patient_name')
                                ->label('Name')
                                ->content(fn (Encounter $record): string => $record->patient?->full_name ?? '—'),
                            Placeholder::make('patient_phone')
                                ->label('Phone')
                                ->content(fn (Encounter $record): string => $record->patient?->phone ?? '—'),
                            Placeholder::make('patient_dob')
                                ->label('Date of Birth')
                                ->content(fn (Encounter $record): string => $record->patient?->date_of_birth?->format('M d, Y') ?? '—'),
                        ]),

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
        ]);
    }
}
