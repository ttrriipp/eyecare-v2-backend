<?php

namespace App\Filament\Resources\Patients\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PatientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Patient Information')->columns(2)->schema([
                TextInput::make('name')->required(),
                TextInput::make('phone')->tel()->nullable(),
                TextInput::make('email')->email()->nullable(),
                DatePicker::make('date_of_birth')->label('Date of Birth')->nullable()->maxDate(now()),
            ]),
        ]);
    }
}
