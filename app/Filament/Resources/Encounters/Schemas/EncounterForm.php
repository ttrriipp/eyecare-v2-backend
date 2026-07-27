<?php

namespace App\Filament\Resources\Encounters\Schemas;

use App\Enums\EncounterStatus;
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
            ]),
            Section::make('Patient Information')->columns(2)->schema([
                Placeholder::make('patient_name')
                    ->label('Patient')
                    ->content(fn (Encounter $record): string => $record->patient?->full_name ?? '—'),
                Placeholder::make('patient_phone')
                    ->label('Phone')
                    ->content(fn (Encounter $record): string => $record->patient?->phone ?? '—'),
                Placeholder::make('patient_dob')
                    ->label('Date of Birth')
                    ->content(fn (Encounter $record): string => $record->patient?->date_of_birth?->format('M d, Y') ?? '—'),
                Placeholder::make('patient_gender')
                    ->label('Gender')
                    ->content(fn (Encounter $record): string => Str::headline($record->patient?->gender ?? '—')),
                Placeholder::make('patient_occupation')
                    ->label('Occupation')
                    ->content(fn (Encounter $record): string => $record->patient?->occupation ?? '—'),
                Placeholder::make('patient_address')
                    ->label('Address')
                    ->content(fn (Encounter $record): string => $record->patient?->address ?? '—'),
                Placeholder::make('appointment_date')
                    ->label('Appointment')
                    ->content(fn (Encounter $record): string => $record->appointment?->scheduled_at?->format('M d, Y g:i A') ?? '—'),
                Placeholder::make('appointment_type')
                    ->label('Appointment Type')
                    ->content(fn (Encounter $record): string => $record->appointment?->appointmentType?->name ?? '—'),
            ]),
            Section::make('Intake Summary')->schema([
                TextInput::make('intake.chief_complaint')
                    ->label('Chief Complaint')
                    ->disabled()
                    ->dehydrated(false)
                    ->columnSpanFull(),
                TextInput::make('intake.past_ocular_history')
                    ->label('Past Ocular History')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('intake.past_surgical_history')
                    ->label('Past Surgical History')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('intake.past_medical_history')
                    ->label('Past Medical History')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('intake.allergies')
                    ->label('Allergies')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('intake.medications')
                    ->label('Medications')
                    ->disabled()
                    ->dehydrated(false),
            ]),
            Section::make('Clinical Findings (Optometrist Only)')
                ->schema([
                    Textarea::make('findings')
                        ->rows(4)
                        ->nullable(),
                    Textarea::make('remarks')
                        ->rows(3)
                        ->nullable(),
                ]),
        ]);
    }
}
