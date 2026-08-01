<?php

namespace App\Filament\Resources\Encounters\Schemas;

use App\Models\Encounter;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Wizard\Step;

class EncounterWizardForm
{
    /**
     * @return array<int, Step>
     */
    public static function steps(): array
    {
        return [
            self::consultationStep(),
            self::examinationStep(),
            self::prescriptionPlanStep(),
            self::reviewCompleteStep(),
        ];
    }

    protected static function consultationStep(): Step
    {
        return Step::make('Consultation & History')
            ->description('Patient context and medical history')
            ->schema([
                Placeholder::make('patient_name')
                    ->label('Patient')
                    ->content(fn (Encounter $record): string => $record->patient?->full_name ?? '—'),
                Placeholder::make('appointment_type')
                    ->label('Appointment Type')
                    ->content(fn (Encounter $record): string => $record->appointment?->appointmentType?->name ?? '—'),
                Textarea::make('chief_complaint')
                    ->label('Chief Complaint')
                    ->rows(3)
                    ->columnSpanFull(),
                Textarea::make('past_ocular_history')
                    ->label('Past Ocular History')
                    ->rows(2)
                    ->columnSpanFull(),
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
            ->columns(2);
    }

    protected static function examinationStep(): Step
    {
        return Step::make('Examination')
            ->description('Clinical findings and examination')
            ->schema([
                Textarea::make('findings')
                    ->label('Examination Findings')
                    ->rows(6)
                    ->columnSpanFull(),
                Textarea::make('remarks')
                    ->label('Examination Remarks')
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }

    protected static function prescriptionPlanStep(): Step
    {
        return Step::make('Prescription & Plan')
            ->description('Treatment plan and prescription')
            ->schema([
                Textarea::make('plan')
                    ->label('Visit Plan')
                    ->rows(4)
                    ->columnSpanFull(),
                Placeholder::make('prescription_status')
                    ->label('Prescription')
                    ->content(function (Encounter $record): string {
                        $prescription = $record->prescriptions()->latest('id')->first();
                        if ($prescription === null) {
                            return 'No prescription created';
                        }

                        return 'Prescription #'.$prescription->id;
                    }),
            ]);
    }

    protected static function reviewCompleteStep(): Step
    {
        return Step::make('Review & Complete')
            ->description('Review and complete the encounter')
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
            ->columns(2);
    }
}
