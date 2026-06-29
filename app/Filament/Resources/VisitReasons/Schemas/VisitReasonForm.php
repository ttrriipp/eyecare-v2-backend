<?php

namespace App\Filament\Resources\VisitReasons\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VisitReasonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                Section::make('Visit Reason Details')
                    ->columnSpan(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('duration_minutes')
                            ->label('Duration (minutes)')
                            ->numeric()
                            ->required()
                            ->default(30)
                            ->minValue(5)
                            ->maxValue(180)
                            ->suffix('min')
                            ->helperText('Used for appointment conflict detection.'),
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
