<?php

namespace App\Filament\Resources\Patients\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PatientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Patient Information')->columns(2)->schema([
                TextInput::make('full_name')
                    ->label('Full Name')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('phone')
                    ->tel()
                    ->nullable(),
                TextInput::make('contact_email')
                    ->label('Email')
                    ->email()
                    ->nullable(),
                DatePicker::make('date_of_birth')
                    ->label('Date of Birth')
                    ->nullable()
                    ->maxDate(now()),
                Select::make('gender')
                    ->options([
                        'male' => 'Male',
                        'female' => 'Female',
                        'other' => 'Other',
                    ])
                    ->nullable(),
                TextInput::make('occupation')
                    ->nullable(),
                TextInput::make('address')
                    ->nullable()
                    ->columnSpanFull(),
            ]),
            Section::make('Account Link')->schema([
                Grid::make(2)->schema([
                    TextInput::make('user_id')
                        ->label('Linked Account ID')
                        ->nullable()
                        ->numeric()
                        ->helperText('Optional. Link to a patient login account.'),
                ]),
            ]),
        ]);
    }
}
