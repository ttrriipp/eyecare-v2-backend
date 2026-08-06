<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;

class EditProfile extends BaseEditProfile
{
    protected function getNameFormComponent(): Component
    {
        return Grid::make(3)->schema([
            TextInput::make('first_name')
                ->label('First Name')
                ->required()
                ->maxLength(255)
                ->autofocus(),
            TextInput::make('last_name')
                ->label('Last Name')
                ->required()
                ->maxLength(255),
            TextInput::make('middle_name')
                ->label('Middle Name')
                ->maxLength(255),
        ]);
    }
}
