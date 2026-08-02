<?php

namespace App\Filament\Resources\Encounters\Schemas;

use App\Enums\EncounterStatus;
use App\Filament\Resources\Prescriptions\Schemas\PrescriptionForm;
use App\Models\Encounter;
use App\Models\Prescription;
use Carbon\CarbonInterface;
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
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
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
                            Placeholder::make('started_at')
                                ->label('Started')
                                ->content(fn (Encounter $record): string => $record->started_at?->format('M j, Y g:i A') ?? '—'),
                        ]),
                ])
                ->visible(fn (Encounter $record): bool => $record->status === EncounterStatus::InProgress),

            Wizard::make([
                Step::make('Consultation & History')
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

                Step::make('Prescription')
                    ->schema([
                        Group::make(PrescriptionForm::components(forEncounter: true))
                            ->statePath('prescription')
                            ->columnSpanFull(),
                    ]),

                Step::make('Encounter Summary')
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

                        Section::make('Consultation')
                            ->schema([
                                Placeholder::make('summary_chief_complaint')
                                    ->label('Chief Complaint')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get,
                                        'chief_complaint',
                                        $record->chief_complaint,
                                    ))
                                    ->columnSpanFull(),
                                Placeholder::make('summary_past_ocular_history')
                                    ->label('Past Ocular History')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get,
                                        'past_ocular_history',
                                        $record->past_ocular_history,
                                    )),
                                Placeholder::make('summary_past_surgical_history')
                                    ->label('Past Surgical History')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get,
                                        'past_surgical_history',
                                        $record->past_surgical_history,
                                    )),
                                Placeholder::make('summary_past_medical_history')
                                    ->label('Past Medical History')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get,
                                        'past_medical_history',
                                        $record->past_medical_history,
                                    )),
                                Placeholder::make('summary_allergies')
                                    ->label('Allergies')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get,
                                        'allergies',
                                        $record->allergies,
                                    )),
                                Placeholder::make('summary_medications')
                                    ->label('Current Medications')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get,
                                        'medications',
                                        $record->medications,
                                    )),
                            ])
                            ->columns(2),

                        Section::make('Examination')
                            ->schema([
                                Placeholder::make('summary_findings')
                                    ->label('Findings')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get,
                                        'findings',
                                        $record->findings,
                                    ))
                                    ->columnSpanFull(),
                                Placeholder::make('summary_remarks')
                                    ->label('Remarks')
                                    ->content(fn (Get $get, Encounter $record): string => self::formValue(
                                        $get,
                                        'remarks',
                                        $record->remarks,
                                    ))
                                    ->columnSpanFull(),
                            ]),

                        Section::make('Prescription')
                            ->visible(fn (Get $get, Encounter $record): bool => self::hasPrescriptionFormData($get) || self::latestPrescription($record) !== null)
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        Placeholder::make('summary_prescription_status')
                                            ->label('Status')
                                            ->content(fn (Get $get, Encounter $record): string => self::prescriptionStatus($get, $record)),
                                        Placeholder::make('summary_prescribed_at')
                                            ->label('Date')
                                            ->content(fn (Get $get, Encounter $record): string => self::prescriptionDate($get, $record)),
                                    ]),
                                Grid::make(2)
                                    ->schema([
                                        Section::make('O.D. (Right Eye)')
                                            ->schema([
                                                Grid::make(3)
                                                    ->schema([
                                                        Placeholder::make('summary_main_od_value')
                                                            ->label('Value')
                                                            ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                                $get,
                                                                'main_od_value',
                                                                self::latestPrescription($record)?->main_od_value,
                                                            )),
                                                        Placeholder::make('summary_main_od_sphere')
                                                            ->label('SPH')
                                                            ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                                $get,
                                                                'main_od_sphere',
                                                                self::latestPrescription($record)?->main_od_sphere,
                                                            )),
                                                        Placeholder::make('summary_main_od_cylinder')
                                                            ->label('CYL')
                                                            ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                                $get,
                                                                'main_od_cylinder',
                                                                self::latestPrescription($record)?->main_od_cylinder,
                                                            )),
                                                    ]),
                                            ]),
                                        Section::make('O.S. (Left Eye)')
                                            ->schema([
                                                Grid::make(3)
                                                    ->schema([
                                                        Placeholder::make('summary_main_os_value')
                                                            ->label('Value')
                                                            ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                                $get,
                                                                'main_os_value',
                                                                self::latestPrescription($record)?->main_os_value,
                                                            )),
                                                        Placeholder::make('summary_main_os_sphere')
                                                            ->label('SPH')
                                                            ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                                $get,
                                                                'main_os_sphere',
                                                                self::latestPrescription($record)?->main_os_sphere,
                                                            )),
                                                        Placeholder::make('summary_main_os_cylinder')
                                                            ->label('CYL')
                                                            ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                                $get,
                                                                'main_os_cylinder',
                                                                self::latestPrescription($record)?->main_os_cylinder,
                                                            )),
                                                    ]),
                                            ]),
                                    ]),
                                Grid::make(2)
                                    ->schema([
                                        Section::make('O.D. Add')
                                            ->schema([
                                                Grid::make(3)
                                                    ->schema([
                                                        Placeholder::make('summary_add_od_value')
                                                            ->label('Value')
                                                            ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                                $get,
                                                                'add_od_value',
                                                                self::latestPrescription($record)?->add_od_value,
                                                            )),
                                                        Placeholder::make('summary_add_od_sphere')
                                                            ->label('SPH')
                                                            ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                                $get,
                                                                'add_od_sphere',
                                                                self::latestPrescription($record)?->add_od_sphere,
                                                            )),
                                                        Placeholder::make('summary_add_od_cylinder')
                                                            ->label('CYL')
                                                            ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                                $get,
                                                                'add_od_cylinder',
                                                                self::latestPrescription($record)?->add_od_cylinder,
                                                            )),
                                                    ]),
                                            ]),
                                        Section::make('O.S. Add')
                                            ->schema([
                                                Grid::make(3)
                                                    ->schema([
                                                        Placeholder::make('summary_add_os_value')
                                                            ->label('Value')
                                                            ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                                $get,
                                                                'add_os_value',
                                                                self::latestPrescription($record)?->add_os_value,
                                                            )),
                                                        Placeholder::make('summary_add_os_sphere')
                                                            ->label('SPH')
                                                            ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                                $get,
                                                                'add_os_sphere',
                                                                self::latestPrescription($record)?->add_os_sphere,
                                                            )),
                                                        Placeholder::make('summary_add_os_cylinder')
                                                            ->label('CYL')
                                                            ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                                                $get,
                                                                'add_os_cylinder',
                                                                self::latestPrescription($record)?->add_os_cylinder,
                                                            )),
                                                    ]),
                                            ]),
                                    ]),
                                Placeholder::make('summary_prescription_remarks')
                                    ->label('Remarks')
                                    ->content(fn (Get $get, Encounter $record): string => self::formPrescriptionValue(
                                        $get,
                                        'remarks',
                                        self::latestPrescription($record)?->remarks,
                                    ))
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ])
                ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                    <x-filament::button
                        type="submit"
                        size="sm"
                        x-on:click="if(!confirm('Are you sure you want to complete this visit? This action cannot be undone.')) $event.preventDefault()"
                    >
                        Complete Visit
                    </x-filament::button>
                BLADE)))
                ->visible(fn (Encounter $record): bool => $record->status === EncounterStatus::InProgress),

            Grid::make(3)
                ->visible(fn (Encounter $record): bool => $record->status !== EncounterStatus::InProgress)
                ->schema([
                    Grid::make(1)->columnSpan(2)->schema([
                        Section::make('Clinical Summary')
                            ->schema([
                                Placeholder::make('view_chief_complaint')
                                    ->label('Chief Complaint')
                                    ->content(fn (Encounter $record): string => $record->chief_complaint ?? '—'),
                                Placeholder::make('view_findings')
                                    ->label('Findings')
                                    ->content(fn (Encounter $record): string => $record->findings ?? '—'),
                                Placeholder::make('view_remarks')
                                    ->label('Remarks')
                                    ->content(fn (Encounter $record): string => $record->remarks ?? '—'),
                            ])
                            ->columns(2),
                    ]),

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

    private static function latestPrescription(Encounter $record): ?Prescription
    {
        return $record->prescriptions()->latest('id')->first();
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
