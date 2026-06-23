<?php

namespace App\Filament\Resources\Patients\Schemas;

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
            ]),
        ]);
    }
}
