<?php

namespace App\Filament\Resources\Services\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            TextInput::make('price')
                ->required()
                ->numeric()
                ->minValue(0)
                ->prefix('₱'),
            Textarea::make('description')
                ->columnSpanFull(),
            Toggle::make('is_active')
                ->label('Visibility')
                ->helperText(
                    fn (bool $state): string => $state
                        ? 'This service is available when billing patients.'
                        : 'This service will be hidden from the billing form.'
                )
                ->default(true),
        ]);
    }
}
