<?php

namespace App\Filament\Resources\Appointments\Schemas;

use App\Actions\Patients\SearchPatientDuplicates;
use App\Filament\Support\PatientDuplicateMatchCard;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Patient;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Illuminate\Support\HtmlString;
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
                            TextInput::make('new_patient_first_name')
                                ->label('First Name')
                                ->required(fn (Get $get): bool => $get('patient_mode') === 'new')
                                ->hidden(fn (Get $get): bool => $get('patient_mode') !== 'new')
                                ->live(onBlur: true)
                                ->dehydrated(false),
                            TextInput::make('new_patient_last_name')
                                ->label('Last Name')
                                ->required(fn (Get $get): bool => $get('patient_mode') === 'new')
                                ->hidden(fn (Get $get): bool => $get('patient_mode') !== 'new')
                                ->live(onBlur: true)
                                ->dehydrated(false),
                            TextInput::make('new_patient_middle_name')
                                ->label('Middle Name')
                                ->nullable()
                                ->hidden(fn (Get $get): bool => $get('patient_mode') !== 'new')
                                ->live(onBlur: true)
                                ->dehydrated(false),
                            TextInput::make('new_patient_phone')
                                ->label('Phone')
                                ->tel()
                                ->nullable()
                                ->prefix('+63')
                                ->formatStateUsing(fn (?string $state): ?string => $state !== null
                                    ? preg_replace('/^\+63/', '', $state)
                                    : null
                                )
                                ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null
                                    ? '+63'.preg_replace('/[^0-9]/', '', $state)
                                    : null
                                )
                                ->hidden(fn (Get $get): bool => $get('patient_mode') !== 'new')
                                ->live(onBlur: true)
                                ->dehydrated(false),
                            TextInput::make('new_patient_contact_email')
                                ->label('Email')
                                ->email()
                                ->nullable()
                                ->hidden(fn (Get $get): bool => $get('patient_mode') !== 'new')
                                ->live(onBlur: true)
                                ->dehydrated(false),
                            DatePicker::make('new_patient_date_of_birth')
                                ->label('Date of Birth')
                                ->nullable()
                                ->maxDate(now())
                                ->hidden(fn (Get $get): bool => $get('patient_mode') !== 'new')
                                ->live(onBlur: true)
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

                            Placeholder::make('new_patient_duplicate_matches')
                                ->hiddenLabel()
                                ->hidden(fn (Get $get): bool => $get('patient_mode') !== 'new')
                                ->columnSpanFull()
                                ->content(function (Get $get): HtmlString {
                                    $phone = filled($get('new_patient_phone'))
                                        ? '+63'.preg_replace('/[^0-9]/', '', $get('new_patient_phone'))
                                        : null;

                                    $fullName = trim(collect([
                                        $get('new_patient_first_name'),
                                        $get('new_patient_middle_name'),
                                        $get('new_patient_last_name'),
                                    ])->filter()->implode(' '));

                                    $matches = app(SearchPatientDuplicates::class)->handle([
                                        'contact_email' => $get('new_patient_contact_email'),
                                        'phone' => $phone,
                                        'full_name' => $fullName,
                                        'date_of_birth' => $get('new_patient_date_of_birth'),
                                    ]);

                                    if ($matches->isEmpty()) {
                                        return new HtmlString('');
                                    }

                                    return PatientDuplicateMatchCard::render(
                                        $matches,
                                    );
                                }),

                            // Patient name (read-only on edit)
                            Placeholder::make('patient_name_display')
                                ->label('Name')
                                ->content(fn (Appointment $record): string => $record->patient?->full_name ?? '—')
                                ->hiddenOn('create')
                                ->columnSpanFull(),

                            // Existing Patient select
                            Select::make('patient_id')
                                ->label('Name')
                                ->options(function () {
                                    return Patient::orderBy('first_name')
                                        ->get()
                                        ->mapWithKeys(fn ($p) => [
                                            $p->id => "{$p->full_name} ({$p->patient_number})",
                                        ])
                                        ->toArray();
                                })
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
                        ->key('appointment-details')
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
                                ->relationship(
                                    name: 'appointmentType',
                                    titleAttribute: 'name',
                                )
                                ->options(AppointmentType::where('is_active', true)->pluck('name', 'id'))
                                ->required()
                                ->live()
                                ->disabled(fn (?Appointment $record): bool => $record !== null && filled($record->checked_in_at))
                                ->afterStateUpdated(function (Set $set, ?string $state): void {
                                    $type = AppointmentType::find($state);
                                    if ($type) {
                                        $set('duration_minutes', $type->duration_minutes);
                                    }
                                }),

                            TextInput::make('duration_minutes')
                                ->label('Duration (minutes)')
                                ->numeric()
                                ->minValue(5)
                                ->maxValue(240)
                                ->step(5)
                                ->default(30)
                                ->required()
                                ->disabled(fn (?Appointment $record): bool => $record !== null && filled($record->checked_in_at))
                                ->hidden(fn (Get $get): bool => $get('is_walk_in') === 'walk_in')
                                ->dehydrated(),
                            TextEntry::make('current_status')
                                ->label('Status')
                                ->state(fn (?Appointment $record): ?string => $record?->status?->name)
                                ->formatStateUsing(fn (?string $state): string => filled($state) ? Str::headline($state) : '—')
                                ->badge()
                                ->color(fn (?string $state): string => match ($state) {
                                    'scheduled' => 'info',
                                    'checked_in' => 'warning',
                                    'fulfilled' => 'success',
                                    'cancelled' => 'danger',
                                    'no_show' => 'gray',
                                    default => 'gray',
                                })
                                ->size(TextSize::Large)
                                ->extraAttributes(['class' => 'appointment-status-entry'])
                                ->hiddenOn('create'),
                            Textarea::make('reason_for_visit')
                                ->label('Reason for Visit')
                                ->rows(3)
                                ->disabled(fn (?Appointment $record): bool => $record !== null && filled($record->checked_in_at))
                                ->columnSpanFull(),
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
                                ->suffixIcon('heroicon-o-clock')
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
                            ->relationship('optometrist', 'first_name', fn ($query) => $query->optometrists())
                            ->getOptionLabelFromRecordUsing(fn (User $user): string => $user->full_name)
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->disabled(fn (?Appointment $record): bool => $record !== null && filled($record->checked_in_at))
                            ->placeholder('Assign later'),
                    ]),

                    Section::make('Timeline')
                        ->hiddenOn('create')
                        ->schema([
                            Placeholder::make('created_at')
                                ->label('Booked')
                                ->content(fn (?Appointment $record): string => $record?->created_at?->diffForHumans() ?? '—'),
                            Placeholder::make('createdBy.full_name')
                                ->label('Booked by')
                                ->content(fn (?Appointment $record): string => $record?->createdBy?->full_name ?? 'System / patient'),
                            Placeholder::make('checked_in_at')
                                ->label('Checked in')
                                ->content(fn (?Appointment $record): string => $record?->checked_in_at?->diffForHumans() ?? '—'),
                            Placeholder::make('checkedInBy.full_name')
                                ->label('Checked in by')
                                ->content(fn (?Appointment $record): string => $record?->checkedInBy?->full_name ?? '—'),
                            Placeholder::make('updated_at')
                                ->label('Last updated')
                                ->content(fn (?Appointment $record): string => $record?->updated_at?->diffForHumans() ?? '—'),
                        ]),
                ]),
            ]),
        ]);
    }
}
