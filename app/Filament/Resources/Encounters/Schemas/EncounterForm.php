<?php

namespace App\Filament\Resources\Encounters\Schemas;

use App\Models\Encounter;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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

                            Section::make('Clinical Data')
                                ->schema([
                                    Textarea::make('chief_complaint')
                                        ->label('Chief Complaint')
                                        ->rows(3)
                                        ->columnSpanFull(),
                                    Textarea::make('findings')
                                        ->label('Findings')
                                        ->rows(4)
                                        ->columnSpanFull(),
                                    Textarea::make('remarks')
                                        ->label('Remarks')
                                        ->rows(3)
                                        ->columnSpanFull(),
                                    Textarea::make('plan')
                                        ->label('Plan')
                                        ->rows(3)
                                        ->columnSpanFull(),
                                ]),
                        ])
                        ->columnSpan(2),

                    // Sidebar
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
        ]);
    }
}
