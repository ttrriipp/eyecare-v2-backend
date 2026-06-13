<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('brand_id')
                    ->relationship('brand', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('category_id')
                    ->relationship('category', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Textarea::make('description')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->default(true),
                TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->prefix('₱'),
                KeyValue::make('dimensions')
                    ->columnSpanFull(),
                Repeater::make('variants')
                    ->relationship()
                    ->schema([
                        TextInput::make('name')
                            ->required(),
                        TextInput::make('sku')
                            ->required()
                            ->unique(ignoreRecord: true),
                        TextInput::make('price')
                            ->required()
                            ->numeric()
                            ->prefix('₱'),
                        TextInput::make('stock_quantity')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        TextInput::make('low_stock_threshold')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        Toggle::make('is_active')
                            ->default(true),
                        Toggle::make('ar_eligible')
                            ->live(),
                        TextInput::make('ar_asset_reference')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => (bool) $get('ar_eligible')),
                        KeyValue::make('dimensions'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Repeater::make('images')
                    ->relationship()
                    ->defaultItems(0)
                    ->schema([
                        FileUpload::make('path')
                            ->disk('public')
                            ->directory('products')
                            ->visibility('public')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios(['1:1', '4:3', '16:9'])
                            ->previewable(false)
                            ->maxSize(5120)
                            ->acceptedFileTypes([
                                'image/jpeg',
                                'image/png',
                                'image/webp',
                            ])
                            ->required(),
                        Placeholder::make('preview')
                            ->label('Current Image')
                            ->content(fn ($record) => $record?->path
                                ? new HtmlString(
                                    '<img src="'.asset('storage/'.$record->path).'" style="max-height:120px;border-radius:6px;" />'
                                )
                                : '—'
                            ),
                        Toggle::make('is_primary')
                            ->default(false),
                        TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
