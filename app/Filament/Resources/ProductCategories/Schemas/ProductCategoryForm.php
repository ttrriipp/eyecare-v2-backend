<?php

namespace App\Filament\Resources\ProductCategories\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Grid::make(3)->schema([
                Section::make('Category Details')
                    ->columnSpan(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
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
