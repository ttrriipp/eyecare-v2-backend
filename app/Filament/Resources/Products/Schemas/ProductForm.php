<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            // ── Top row: main (2/3) + sidebar (1/3) ──────────────────
            Grid::make(3)->schema([
                // ── Left: main content (2/3) ──────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
                    Section::make('Product Details')
                        ->schema([
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
                                ->unique(ignoreRecord: true)
                                ->disabled()
                                ->dehydrated(),
                            RichEditor::make('description')
                                ->toolbarButtons([
                                    ['bold', 'italic', 'underline', 'strike'],
                                    ['h2', 'h3'],
                                    ['bulletList', 'orderedList'],
                                    ['blockquote'],
                                    ['undo', 'redo'],
                                ])
                                ->columnSpanFull(),
                        ])
                        ->columns(2),

                    Section::make('Images')->schema([
                        FileUpload::make('images')
                            ->disk('public')
                            ->directory('products')
                            ->visibility('public')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->appendFiles()
                            ->imageEditor()
                            ->maxSize(5120)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->hiddenLabel(),
                    ]),
                ]),

                // ── Sidebar ──────────────────────────────────────────
                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make('Status')->schema([
                            Select::make('product_type')
                                ->label('Product Type')
                                ->options([
                                    'frame' => 'Frame',
                                    'accessory' => 'Accessory',
                                ])
                                ->default('frame')
                                ->required()
                                ->live(),
                            Toggle::make('is_active')
                                ->label('Visibility')
                                ->helperText(fn (bool $state): string => $state
                                    ? 'This product is visible to customers.'
                                    : 'This product will be hidden from all sales channels.'
                                )
                                ->default(true),
                        ]),

                        Section::make('Associations')->schema([
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
                        ]),
                    ]),
            ]),

            // ── Inline variants (create only, full width) ─────────────
            Section::make('Variants')
                ->columnSpanFull()
                ->hiddenOn('edit')
                ->description('Add at least one variant with price and stock.')
                ->schema([
                    Repeater::make('variants')
                        ->relationship()
                        ->minItems(1)
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('name')->required(),
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
                            Toggle::make('ar_eligible')->live()
                                ->visible(fn (Get $get): bool => $get('../../product_type') === 'frame'),
                            TextInput::make('ar_asset_reference')
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => $get('../../product_type') === 'frame' && (bool) $get('ar_eligible')),
                            KeyValue::make('dimensions')->columnSpanFull()
                                ->visible(fn (Get $get): bool => $get('../../product_type') === 'frame'),
                        ])
                        ->columns(2),
                ]),
        ]);
    }
}
