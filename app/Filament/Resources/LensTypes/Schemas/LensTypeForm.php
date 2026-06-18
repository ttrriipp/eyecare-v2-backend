<?php

namespace App\Filament\Resources\LensTypes\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LensTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('price')
                    ->numeric()
                    ->prefix('₱')
                    ->helperText('Price added to order total when this lens type is selected.'),
                Textarea::make('description')
                    ->columnSpanFull(),
            ]);
    }
}
