<?php

namespace App\Filament\Resources\Encounters\Schemas;

use App\Enums\EncounterStatus;
use App\Models\User;
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
                TextInput::make('patient.full_name')
                    ->label('Patient')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('appointment.scheduled_at')
                    ->label('Appointment')
                    ->disabled()
                    ->dehydrated(false),
            ]),
            Section::make('Intake Summary')->schema([
                TextInput::make('intake.chief_complaint')
                    ->label('Chief Complaint')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('intake.allergies')
                    ->label('Allergies')
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
