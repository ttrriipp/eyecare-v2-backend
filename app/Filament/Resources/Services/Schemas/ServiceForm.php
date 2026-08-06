<?php

namespace App\Filament\Resources\Services\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                Section::make('Service Details')
                    ->columnSpan(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('price')
                            ->required()
                            ->numeric()
                            ->prefix('₱')
                            ->helperText('Default price when this service is billed or quoted.'),
                        Textarea::make('description')
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),

                Section::make('Timestamps')
                    ->columnSpan(1)
                    ->schema([
                        Placeholder::make('created_at')
                            ->label('Created at')
                            ->content(fn ($record): string => $record?->created_at?->diffForHumans() ?? '—'),
                        Placeholder::make('updated_at')
                            ->label('Last modified')
                            ->content(fn ($record): string => $record?->updated_at?->diffForHumans() ?? '—'),
                    ]),
            ]),
        ]);
    }
}
