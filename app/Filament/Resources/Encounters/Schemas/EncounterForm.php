<?php

namespace App\Filament\Resources\Encounters\Schemas;

use App\Enums\EncounterStatus;
use App\Filament\Resources\Encounters\Pages\EditEncounter;
use App\Filament\Resources\Prescriptions\Schemas\PrescriptionForm;
use App\Models\BillingRecord;
use App\Models\Encounter;
use App\Models\Prescription;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EncounterForm
{
    /**
     * @var array<int, string>
     */
    private const PRESCRIPTION_DATA_FIELDS = [
        'main_od_value',
        'main_od_sphere',
        'main_od_cylinder',
        'main_os_value',
        'main_os_sphere',
        'main_os_cylinder',
        'add_od_value',
        'add_od_sphere',
        'add_od_cylinder',
        'add_os_value',
        'add_os_sphere',
        'add_os_cylinder',
        'remarks',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()
                ->schema([
                    Grid::make(4)
                        ->schema([
                            Placeholder::make('encounter_number')
                                ->label('Encounter #')
                                ->content(fn (Encounter $record): string => $record->encounter_number),
                            Placeholder::make('patient_name')
                                ->label('Patient')
                                ->content(fn (Encounter $record): string => $record->patient?->full_name ?? '—'),
                            Placeholder::make('appointment_type')
                                ->label('Appointment Type')
                                ->content(fn (Encounter $record): string => $record->appointment?->appointmentType?->name ?? '—'),
                            Placeholder::make('optometrist_name')
                                ->label('Optometrist')
                                ->content(fn (Encounter $record): string => $record->optometrist?->full_name ?? 'Not assigned'),
                            Placeholder::make('started_at')
                                ->label('Started')
                                ->content(fn (Encounter $record): string => $record->started_at?->format('M j, Y g:i A') ?? '—'),
                        ]),
                ])
                ->visible(fn (Encounter $record): bool => $record->status === EncounterStatus::InProgress),

            Wizard::make([
                Step::make('History')
                    ->schema([
                        Textarea::make('chief_complaint')
                            ->label('Chief Complaint')
                            ->required()
                            ->validationAttribute('Chief Complaint')
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
                    ->columns(2)
                    ->afterValidation(function (EditEncounter $livewire): void {
                        $livewire->saveDraft(1);
                    }),

                Step::make('Examination')
                    ->schema([
                        Textarea::make('findings')
                            ->label('Examination Findings')
                            ->required()
                            ->validationAttribute('Examination Findings')
                            ->rows(6)
                            ->columnSpanFull(),
                        Textarea::make('supporting_test_results')
                            ->label('Supporting Test Results')
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->afterValidation(function (EditEncounter $livewire): void {
                        $livewire->saveDraft(2);
                    }),

                Step::make('Assessment & Plan')
                    ->schema([
                        Textarea::make('assessment')
                            ->label('Assessment')
                            ->required()
                            ->validationAttribute('Assessment')
                            ->rows(4)
                            ->columnSpanFull(),
                        Textarea::make('plan')
                            ->label('Plan')
                            ->required()
                            ->validationAttribute('Plan')
                            ->rows(4)
                            ->columnSpanFull(),
                        Section::make('Prescription (Optional)')
                            ->schema([
                                Group::make(PrescriptionForm::components(forEncounter: true))
                                    ->statePath('prescription')
                                    ->columnSpanFull(),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->afterValidation(function (EditEncounter $livewire): void {
                        $livewire->saveDraft(3);
                    }),

                Step::make('Review & Complete')
                    ->schema([
                        Section::make('Patient')
                            ->schema([
                                Placeholder::make('summary_patient_name')
                                    ->label('Name')
                                    ->content(fn (Encounter $record): string => $record->patient?->full_name ?? '—'),
                                Placeholder::make('summary_patient_dob')
                                    ->label('Date of Birth')
                                    ->content(fn (Encounter $record): string => $record->patient?->date_of_birth?->format('M d, Y') ?? '—'),
                                Placeholder::make('summary_patient_gender')
                                    ->label('Gender')
                                    ->content(fn (Encounter $record): string => Str::headline($record->patient?->gender ?? '—')),
                                Placeholder::make('summary_patient_phone')
                                    ->label('Phone')
                                    ->content(fn (Encounter $record): string => $record->patient?->phone ?? '—'),
                                Placeholder::make('summary_patient_email')
                                    ->label('Email')
                                    ->content(fn (Encounter $record): string => $record->patient?->contact_email ?? '—'),
                                Placeholder::make('summary_patient_address')
                                    ->label('Address')
                                    ->content(fn (Encounter $record): string => $record->patient?->address ?? '—')
                                    ->columnSpanFull(),
                            ])
                            ->columns(3),

                        Section::make('History')
                            ->schema([
                                Placeholder::make('summary_chief_complaint')
                                    ->label('Chief Complaint')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get, 'chief_complaint', $record->chief_complaint,
                                    ))
                                    ->columnSpanFull(),
                                Placeholder::make('summary_past_ocular_history')
                                    ->label('Past Ocular History')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get, 'past_ocular_history', $record->past_ocular_history,
                                    )),
                                Placeholder::make('summary_past_surgical_history')
                                    ->label('Past Surgical History')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get, 'past_surgical_history', $record->past_surgical_history,
                                    )),
                                Placeholder::make('summary_past_medical_history')
                                    ->label('Past Medical History')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get, 'past_medical_history', $record->past_medical_history,
                                    )),
                                Placeholder::make('summary_allergies')
                                    ->label('Allergies')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get, 'allergies', $record->allergies,
                                    )),
                                Placeholder::make('summary_medications')
                                    ->label('Current Medications')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get, 'medications', $record->medications,
                                    )),
                            ])
                            ->columns(2),

                        Section::make('Examination')
                            ->schema([
                                Placeholder::make('summary_findings')
                                    ->label('Findings')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get, 'findings', $record->findings,
                                    ))
                                    ->columnSpanFull(),
                                Placeholder::make('summary_supporting_test_results')
                                    ->label('Supporting Test Results')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get, 'supporting_test_results', $record->supporting_test_results,
                                    ))
                                    ->columnSpanFull(),
                            ]),

                        Section::make('Assessment & Plan')
                            ->schema([
                                Placeholder::make('summary_assessment')
                                    ->label('Assessment')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get, 'assessment', $record->assessment,
                                    ))
                                    ->columnSpanFull(),
                                Placeholder::make('summary_plan')
                                    ->label('Plan')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get, 'plan', $record->plan,
                                    ))
                                    ->columnSpanFull(),
                            ]),

                        Section::make('Prescription')
                            ->visible(fn (Get $get, Encounter $record): bool => self::hasPrescriptionFormData($get) || self::latestPrescription($record) !== null)
                            ->schema([
                                Section::make('Prescription')
                                    ->schema([
                                        Grid::make(3)
                                            ->schema([
                                                Placeholder::make('summary_main_od_value')
                                                    ->label('O.D.')
                                                    ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                        $get, 'main_od_value', self::latestPrescription($record)?->main_od_value,
                                                    )),
                                                Placeholder::make('summary_main_od_sphere')
                                                    ->label('SPH')
                                                    ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                        $get, 'main_od_sphere', self::latestPrescription($record)?->main_od_sphere,
                                                    )),
                                                Placeholder::make('summary_main_od_cylinder')
                                                    ->label('CX')
                                                    ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                        $get, 'main_od_cylinder', self::latestPrescription($record)?->main_od_cylinder,
                                                    )),
                                            ]),
                                        Grid::make(3)
                                            ->schema([
                                                Placeholder::make('summary_main_os_value')
                                                    ->label('O.S.')
                                                    ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                        $get, 'main_os_value', self::latestPrescription($record)?->main_os_value,
                                                    )),
                                                Placeholder::make('summary_main_os_sphere')
                                                    ->label('SPH')
                                                    ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                        $get, 'main_os_sphere', self::latestPrescription($record)?->main_os_sphere,
                                                    )),
                                                Placeholder::make('summary_main_os_cylinder')
                                                    ->label('CX')
                                                    ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                        $get, 'main_os_cylinder', self::latestPrescription($record)?->main_os_cylinder,
                                                    )),
                                            ]),
                                    ]),

                                Section::make('ADD')
                                    ->schema([
                                        Grid::make(3)
                                            ->schema([
                                                Placeholder::make('summary_add_od_value')
                                                    ->label('O.D.')
                                                    ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                        $get, 'add_od_value', self::latestPrescription($record)?->add_od_value,
                                                    )),
                                                Placeholder::make('summary_add_od_sphere')
                                                    ->label('SPH')
                                                    ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                        $get, 'add_od_sphere', self::latestPrescription($record)?->add_od_sphere,
                                                    )),
                                                Placeholder::make('summary_add_od_cylinder')
                                                    ->label('CX')
                                                    ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                        $get, 'add_od_cylinder', self::latestPrescription($record)?->add_od_cylinder,
                                                    )),
                                            ]),
                                        Grid::make(3)
                                            ->schema([
                                                Placeholder::make('summary_add_os_value')
                                                    ->label('O.S.')
                                                    ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                        $get, 'add_os_value', self::latestPrescription($record)?->add_os_value,
                                                    )),
                                                Placeholder::make('summary_add_os_sphere')
                                                    ->label('SPH')
                                                    ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                        $get, 'add_os_sphere', self::latestPrescription($record)?->add_os_sphere,
                                                    )),
                                                Placeholder::make('summary_add_os_cylinder')
                                                    ->label('CX')
                                                    ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                        $get, 'add_os_cylinder', self::latestPrescription($record)?->add_os_cylinder,
                                                    )),
                                            ]),
                                    ]),

                                Section::make('Details')
                                    ->schema([
                                        Placeholder::make('summary_prescription_remarks')
                                            ->label('Remarks')
                                            ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                $get, 'remarks', self::latestPrescription($record)?->remarks,
                                            ))
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),
            ])
                ->nextAction(fn (Action $action): Action => $action
                    ->label('Save & Continue')
                    ->icon('heroicon-o-arrow-right'))
                ->previousAction(fn (Action $action): Action => $action
                    ->label('Back')
                    ->icon('heroicon-o-arrow-left'))
                ->submitAction(view('filament.encounters.complete-visit-button'))
                ->visible(fn (Encounter $record): bool => $record->status === EncounterStatus::InProgress),

            Section::make('Encounter Details')
                ->visible(fn (Encounter $record): bool => $record->status !== EncounterStatus::InProgress)
                ->schema([
                    Placeholder::make('encounter_number')
                        ->label('Encounter #')
                        ->content(fn (Encounter $record): string => $record->encounter_number),
                    Placeholder::make('status')
                        ->label('Status')
                        ->content(fn (Encounter $record): string => Str::headline($record->status->value))
                        ->badge()
                        ->color(fn (Encounter $record): string => match ($record->status) {
                            EncounterStatus::Planned => 'gray',
                            EncounterStatus::InProgress => 'warning',
                            EncounterStatus::Completed => 'success',
                            EncounterStatus::Cancelled => 'danger',
                        }),
                    Placeholder::make('appointment_type')
                        ->label('Appointment Type')
                        ->content(fn (Encounter $record): string => $record->appointment?->appointmentType?->name ?? '—'),
                    Placeholder::make('optometrist')
                        ->label('Optometrist')
                        ->content(fn (Encounter $record): string => $record->optometrist?->full_name ?? 'Not assigned'),
                    Placeholder::make('started_at')
                        ->label('Started')
                        ->content(fn (Encounter $record): string => $record->started_at?->format('M j, Y g:i A') ?? '—'),
                    Placeholder::make('completed_at')
                        ->label('Completed')
                        ->content(fn (Encounter $record): string => $record->completed_at?->format('M j, Y g:i A') ?? '—'),
                ])
                ->columns(3),

            Section::make('Patient')
                ->visible(fn (Encounter $record): bool => $record->status !== EncounterStatus::InProgress)
                ->schema([
                    Placeholder::make('patient_name')
                        ->label('Name')
                        ->content(fn (Encounter $record): string => $record->patient?->full_name ?? '—'),
                    Placeholder::make('patient_dob')
                        ->label('Date of Birth')
                        ->content(fn (Encounter $record): string => $record->patient?->date_of_birth?->format('M d, Y') ?? '—'),
                    Placeholder::make('patient_gender')
                        ->label('Gender')
                        ->content(fn (Encounter $record): string => Str::headline($record->patient?->gender ?? '—')),
                    Placeholder::make('patient_phone')
                        ->label('Phone')
                        ->content(fn (Encounter $record): string => $record->patient?->phone ?? '—'),
                    Placeholder::make('patient_email')
                        ->label('Email')
                        ->content(fn (Encounter $record): string => $record->patient?->contact_email ?? '—'),
                    Placeholder::make('patient_address')
                        ->label('Address')
                        ->content(fn (Encounter $record): string => $record->patient?->address ?? '—')
                        ->columnSpanFull(),
                ])
                ->columns(3),

            Section::make('Consultation')
                ->visible(fn (Encounter $record): bool => ! in_array($record->status, [EncounterStatus::Planned, EncounterStatus::InProgress], true))
                ->schema([
                    Placeholder::make('view_chief_complaint')
                        ->label('Chief Complaint')
                        ->content(fn (Encounter $record): string => $record->chief_complaint ?? '—')
                        ->columnSpanFull(),
                    Placeholder::make('view_past_ocular_history')
                        ->label('Past Ocular History')
                        ->content(fn (Encounter $record): string => $record->past_ocular_history ?? '—'),
                    Placeholder::make('view_past_surgical_history')
                        ->label('Past Surgical History')
                        ->content(fn (Encounter $record): string => $record->past_surgical_history ?? '—'),
                    Placeholder::make('view_past_medical_history')
                        ->label('Past Medical History')
                        ->content(fn (Encounter $record): string => $record->past_medical_history ?? '—'),
                    Placeholder::make('view_allergies')
                        ->label('Allergies')
                        ->content(fn (Encounter $record): string => $record->allergies ?? '—'),
                    Placeholder::make('view_medications')
                        ->label('Current Medications')
                        ->content(fn (Encounter $record): string => $record->medications ?? '—'),
                ])
                ->columns(2),

            Section::make('Findings')
                ->visible(fn (Encounter $record): bool => ! in_array($record->status, [EncounterStatus::Planned, EncounterStatus::InProgress], true))
                ->schema([
                    Placeholder::make('view_findings')
                        ->label('Findings')
                        ->content(fn (Encounter $record): string => $record->findings ?? '—')
                        ->columnSpanFull(),
                    Placeholder::make('view_supporting_test_results')
                        ->label('Supporting Test Results')
                        ->content(fn (Encounter $record): string => $record->supporting_test_results ?? '—')
                        ->columnSpanFull(),
                ]),

            Section::make('Assessment & Plan')
                ->visible(fn (Encounter $record): bool => ! in_array($record->status, [EncounterStatus::Planned, EncounterStatus::InProgress], true))
                ->schema([
                    Placeholder::make('view_assessment')
                        ->label('Assessment')
                        ->content(fn (Encounter $record): string => $record->assessment ?? '—')
                        ->columnSpanFull(),
                    Placeholder::make('view_plan')
                        ->label('Plan')
                        ->content(fn (Encounter $record): string => $record->plan ?? '—')
                        ->columnSpanFull(),
                ]),

            Section::make('Addenda')
                ->visible(fn (Encounter $record): bool => $record->status === EncounterStatus::Completed && $record->addenda()->exists())
                ->schema([
                    Placeholder::make('addenda_list')
                        ->label('')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->content(function (Encounter $record): string {
                            $addenda = $record->addenda()->with('author')->get();

                            if ($addenda->isEmpty()) {
                                return '—';
                            }

                            $lines = $addenda->map(function ($addendum): string {
                                $type = $addendum->type->value === 'correction' ? 'Correction' : 'Supplement';
                                $author = $addendum->author?->full_name ?? '—';
                                $date = $addendum->authored_at?->format('M j, Y g:i A') ?? '—';

                                return "**[{$type}] #{$addendum->sequence_number}** — {$author} ({$date})\n{$addendum->reason}\n{$addendum->content}";
                            });

                            return $lines->implode("\n\n");
                        }),
                ]),
        ]);
    }

    private static function latestPrescription(Encounter $record): ?Prescription
    {
        return $record->prescriptions()->latest('id')->first();
    }

    private static function latestBillingRecord(Encounter $record): ?BillingRecord
    {
        return BillingRecord::query()
            ->where('encounter_id', $record->id)
            ->whereNull('deleted_at')
            ->latest('id')
            ->first();
    }

    private static function prescriptionStatus(Get $get, Encounter $record): string
    {
        if (self::latestPrescription($record) !== null) {
            return 'Finalized';
        }

        return self::hasPrescriptionFormData($get) ? 'Draft' : 'Not entered';
    }

    private static function prescriptionDate(Get $get, Encounter $record): string
    {
        $prescription = self::latestPrescription($record);

        if ($prescription === null && ! self::hasPrescriptionFormData($get)) {
            return '—';
        }

        return self::formDateValue($get, 'prescription.prescribed_at', $prescription?->prescribed_at);
    }

    private static function hasPrescriptionFormData(Get $get): bool
    {
        return collect(self::PRESCRIPTION_DATA_FIELDS)
            ->contains(fn (string $field): bool => filled($get("prescription.{$field}")));
    }

    private static function formValue(Get $get, string $path, mixed $fallback): string
    {
        return self::displayValue($get($path) ?? $fallback);
    }

    private static function formPrescriptionValue(Get $get, string $field, mixed $fallback): string
    {
        $value = $get("prescription.{$field}") ?? $fallback;

        if (blank($value)) {
            return '—';
        }

        return is_numeric($value)
            ? number_format((float) $value, 2, '.', '')
            : (string) $value;
    }

    private static function formDateValue(Get $get, string $path, mixed $fallback): string
    {
        $value = $get($path) ?? $fallback;

        if (blank($value)) {
            return '—';
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('M j, Y');
        }

        return Carbon::parse($value)->format('M j, Y');
    }

    private static function displayValue(mixed $value): string
    {
        return filled($value) ? (string) $value : '—';
    }
}
