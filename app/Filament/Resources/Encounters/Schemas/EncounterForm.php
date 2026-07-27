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
                Placeholder::make('appointment_date')
                    ->label('Appointment')
                    ->content(fn (Encounter $record): string => $record->appointment?->scheduled_at?->format('M d, Y g:i A') ?? '—'),
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
