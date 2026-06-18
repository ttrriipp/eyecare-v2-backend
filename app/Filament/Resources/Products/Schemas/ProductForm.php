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
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

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
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set(
                        'slug',
                        Str::slug($state ?? ''),
                    )),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Textarea::make('description')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label('Visibility')
                    ->helperText(fn (bool $state): string => $state
                        ? 'This product is visible to customers.'
                        : 'This product will be hidden from all sales channels.'
                    )
                    ->default(true),
                Repeater::make('variants')
                    ->relationship()
                    ->minItems(1)
                    ->schema([
                        TextInput::make('name')
                            ->required(),
                        TextInput::make('sku')
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->placeholder('Auto-generated if blank'),
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
                            ->label('Visibility')
                            ->helperText(fn (bool $state): string => $state
                                ? 'This variant is available to customers.'
                                : 'This variant will be hidden from all sales channels.'
                            )
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
                            ->imageAspectRatio('1:1')
                            ->automaticallyOpenImageEditorForAspectRatio('1:1')
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
                                    '<img src="'.asset('storage/'.$record->path).'" style="max-width:100%;border-radius:6px;" />'
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
