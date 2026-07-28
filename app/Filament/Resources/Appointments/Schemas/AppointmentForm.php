<?php

namespace App\Filament\Resources\Appointments\Schemas;

use App\Enums\IntakeStatus;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Patient;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AppointmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                // ── Main (2/3) ──────────────────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
                    Section::make('Patient Details')
                        ->schema([
                            ToggleButtons::make('patient_mode')
                                ->options([
                                    'new' => 'New Patient',
                                    'existing' => 'Existing Patient',
                                ])
                                ->default('new')
                                ->live()
                                ->inline()
                                ->hiddenOn('edit')
                                ->columnSpanFull(),

                            // New Patient fields
                            TextInput::make('new_patient_full_name')
                                ->label('Full Name')
                                ->required(fn (Get $get): bool => $get('patient_mode') === 'new')
                                ->hidden(fn (Get $get): bool => $get('patient_mode') !== 'new')
                                ->dehydrated(false)
                                ->columnSpanFull(),
                            TextInput::make('new_patient_phone')
                                ->label('Phone')
                                ->tel()
                                ->nullable()
                                ->hidden(fn (Get $get): bool => $get('patient_mode') !== 'new')
                                ->dehydrated(false),
                            TextInput::make('new_patient_contact_email')
                                ->label('Email')
                                ->email()
                                ->nullable()
                                ->hidden(fn (Get $get): bool => $get('patient_mode') !== 'new')
                                ->dehydrated(false),
                            DatePicker::make('new_patient_date_of_birth')
                                ->label('Date of Birth')
                                ->nullable()
                                ->maxDate(now())
                                ->hidden(fn (Get $get): bool => $get('patient_mode') !== 'new')
                                ->dehydrated(false),
                            Select::make('new_patient_gender')
                                ->label('Gender')
                                ->options([
                                    'male' => 'Male',
                                    'female' => 'Female',
                                    'other' => 'Other',
                                ])
                                ->nullable()
                                ->hidden(fn (Get $get): bool => $get('patient_mode') !== 'new')
                                ->dehydrated(false),
                            TextInput::make('new_patient_occupation')
                                ->label('Occupation')
                                ->nullable()
                                ->hidden(fn (Get $get): bool => $get('patient_mode') !== 'new')
                                ->dehydrated(false),
                            TextInput::make('new_patient_address')
                                ->label('Address')
                                ->nullable()
                                ->hidden(fn (Get $get): bool => $get('patient_mode') !== 'new')
                                ->dehydrated(false)
                                ->columnSpanFull(),

                            // Patient name (read-only on edit)
                            Placeholder::make('patient_name_display')
                                ->label('Name')
                                ->content(fn (Appointment $record): string => $record->patient?->full_name ?? '—')
                                ->hiddenOn('create')
                                ->columnSpanFull(),

                            // Existing Patient select
                            Select::make('patient_id')
                                ->label('Name')
                                ->relationship('patient', 'full_name')
                                ->required(fn (Get $get): bool => $get('patient_mode') === 'existing')
                                ->searchable()
                                ->preload()
                                ->live()
                                ->hiddenOn('edit')
                                ->visible(fn (Get $get): bool => $get('patient_mode') === 'existing')
                                ->dehydrated()
                                ->columnSpanFull(),

                            // Existing Patient details
                            Placeholder::make('existing_patient_phone')
                                ->label('Phone')
                                ->content(fn (Get $get): string => Patient::find($get('patient_id'))?->phone ?? '—')
                                ->hidden(fn (Get $get, ?string $operation): bool => blank($get('patient_id')) || ($operation !== 'edit' && $get('patient_mode') !== 'existing')),
                            Placeholder::make('existing_patient_email')
                                ->label('Email')
                                ->content(fn (Get $get): string => Patient::find($get('patient_id'))?->contact_email ?? '—')
                                ->hidden(fn (Get $get, ?string $operation): bool => blank($get('patient_id')) || ($operation !== 'edit' && $get('patient_mode') !== 'existing')),
                            Placeholder::make('existing_patient_dob')
                                ->label('Date of Birth')
                                ->content(fn (Get $get): string => Patient::find($get('patient_id'))?->date_of_birth?->format('M d, Y') ?? '—')
                                ->hidden(fn (Get $get, ?string $operation): bool => blank($get('patient_id')) || ($operation !== 'edit' && $get('patient_mode') !== 'existing')),
                            Placeholder::make('existing_patient_gender')
                                ->label('Gender')
                                ->content(fn (Get $get): string => Str::headline(Patient::find($get('patient_id'))?->gender ?? '—'))
                                ->hidden(fn (Get $get, ?string $operation): bool => blank($get('patient_id')) || ($operation !== 'edit' && $get('patient_mode') !== 'existing')),
                            Placeholder::make('existing_patient_occupation')
                                ->label('Occupation')
                                ->content(fn (Get $get): string => Patient::find($get('patient_id'))?->occupation ?? '—')
                                ->hidden(fn (Get $get, ?string $operation): bool => blank($get('patient_id')) || ($operation !== 'edit' && $get('patient_mode') !== 'existing')),
                            Placeholder::make('existing_patient_address')
                                ->label('Address')
                                ->content(fn (Get $get): string => Patient::find($get('patient_id'))?->address ?? '—')
                                ->hidden(fn (Get $get, ?string $operation): bool => blank($get('patient_id')) || ($operation !== 'edit' && $get('patient_mode') !== 'existing')),
                        ])
                        ->columns(2),

                    Section::make('Appointment Details')
                        ->schema([
                            TextInput::make('appointment_number')
                                ->label('Appointment #')
                                ->disabled()
                                ->dehydrated(false)
                                ->hiddenOn('create')
                                ->columnSpanFull(),
                            ToggleButtons::make('is_walk_in')
                                ->label('Visit Type')
                                ->options([
                                    'scheduled' => 'Scheduled',
                                    'walk_in' => 'Walk-in',
                                ])
                                ->default('scheduled')
                                ->live()
                                ->inline()
                                ->required()
                                ->hiddenOn('edit'),
                            Select::make('appointment_type_id')
                                ->label('Appointment Type')
                                ->relationship('appointmentType', 'name')
                                ->required()
                                ->live()
                                ->disabled(fn (?Appointment $record): bool => $record !== null && filled($record->checked_in_at))
                                ->dehydrated(),
                            Placeholder::make('current_status')
                                ->label('Status')
                                ->content(fn (?Appointment $record): string => $record?->status?->name ? Str::headline($record->status->name) : '—')
                                ->hiddenOn('create'),
                            TextInput::make('referring_source')
                                ->label('Referring Source')
                                ->placeholder('Name of referring doctor or clinic')
                                ->visible(fn (Get $get): bool => AppointmentType::find($get('appointment_type_id'))?->requires_referral ?? false)
                                ->disabled(fn (?Appointment $record): bool => $record !== null && filled($record->checked_in_at))
                                ->dehydrated()
                                ->columnSpanFull(),
                            DatePicker::make('scheduled_at')
                                ->label('Appointment date')
                                ->required(fn (Get $get): bool => $get('is_walk_in') !== 'walk_in')
                                ->native(false)
                                ->displayFormat('M d, Y')
                                ->placeholder('Choose an appointment date')
                                ->suffixIcon('heroicon-o-calendar-days')
                                ->minDate(today())
                                ->disabled(fn (?Appointment $record): bool => $record !== null && filled($record->checked_in_at))
                                ->dehydrated(fn (string $operation): bool => $operation === 'create')
                                ->rule(fn (string $operation): string => $operation === 'create' ? 'after_or_equal:today' : '')
                                ->hidden(fn (Get $get): bool => $get('is_walk_in') === 'walk_in'),
                            TimePicker::make('appointment_time')
                                ->label('Appointment time')
                                ->required(fn (string $operation, Get $get): bool => $operation === 'create' && $get('is_walk_in') !== 'walk_in')
                                ->seconds(false)
                                ->minutesStep(1)
                                ->format('H:i')
                                ->afterStateHydrated(function (TimePicker $component, ?Appointment $record): void {
                                    if ($record) {
                                        $component->state($record->scheduled_at->format('H:i'));
                                    }
                                })
                                ->disabled(fn (?Appointment $record): bool => $record !== null && filled($record->checked_in_at))
                                ->dehydrated(fn (string $operation): bool => $operation === 'create')
                                ->hidden(fn (Get $get): bool => $get('is_walk_in') === 'walk_in'),
                            Textarea::make('staff_notes')
                                ->label('Notes')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),
                ]),

                // ── Sidebar (1/3) ────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    Section::make('Clinical Assignment')->schema([
                        Select::make('optometrist_id')
                            ->label('Optometrist')
                            ->relationship('optometrist', 'name', fn ($query) => $query->optometrists())
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->disabled(fn (?Appointment $record): bool => $record !== null && filled($record->checked_in_at))
                            ->placeholder('Assign later'),
                    ]),

                    Section::make('Patient Intake')
                        ->hiddenOn('create')
                        ->schema([
                            Placeholder::make('intake_status_display')
                                ->label('Status')
                                ->content(function (?Appointment $record): string {
                                    if ($record === null) {
                                        return 'Not Started';
                                    }

                                    $intake = $record->intake;

                                    if ($intake === null) {
                                        return 'Not Started';
                                    }

                                    return match ($intake->status) {
                                        IntakeStatus::Draft => 'Draft',
                                        IntakeStatus::Submitted => 'Submitted',
                                        IntakeStatus::Verified => 'Verified',
                                        default => 'Not Started',
                                    };
                                })
                                ->view('filament.forms.components.intake-status-badge'),
                        ]),

                    Section::make('Timeline')
                        ->hiddenOn('create')
                        ->schema([
                            Placeholder::make('created_at')
                                ->label('Booked')
                                ->content(fn (?Appointment $record): string => $record?->created_at?->diffForHumans() ?? '—'),
                            Placeholder::make('createdBy.name')
                                ->label('Booked by')
                                ->content(fn (?Appointment $record): string => $record?->createdBy?->name ?? 'System / patient'),
                            Placeholder::make('checked_in_at')
                                ->label('Checked in')
                                ->content(fn (?Appointment $record): string => $record?->checked_in_at?->diffForHumans() ?? '—'),
                            Placeholder::make('checkedInBy.name')
                                ->label('Checked in by')
                                ->content(fn (?Appointment $record): string => $record?->checkedInBy?->name ?? '—'),
                            Placeholder::make('updated_at')
                                ->label('Last updated')
                                ->content(fn (?Appointment $record): string => $record?->updated_at?->diffForHumans() ?? '—'),
                        ]),
                ]),
            ]),
        ]);
    }
}
