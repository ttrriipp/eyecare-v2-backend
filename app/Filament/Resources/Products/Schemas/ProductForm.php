<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
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
                            Select::make('product_type')
                                ->label('Product Type')
                                ->options(Product::TYPE_OPTIONS)
                                ->default('frame')
                                ->required()
                                ->live()
                                ->disabledOn('edit')
                                ->dehydrated()
                                ->columnSpanFull(),
                            Select::make('lens_category_id')
                                ->label('Lens Category')
                                ->relationship('lensCategory', 'name')
                                ->searchable()
                                ->preload()
                                ->visible(fn (Get $get): bool => $get('product_type') === 'lens'),
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
                                    ['bulletList', 'orderedList'],
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
                            ->imageEditor()
                            ->multiple()
                            ->reorderable()
                            ->appendFiles()
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
                            Toggle::make('is_active')
                                ->label('Active')
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
                                ->nullable()
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
                            TextInput::make('compare_at_price')
                                ->label('Compare at Price')
                                ->numeric()
                                ->prefix('₱'),
                            TextInput::make('cost_price')
                                ->label('Cost Price')
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
                            TextInput::make('target_stock_level')
                                ->label('Target Stock Level')
                                ->nullable()
                                ->integer()
                                ->minValue(0)
                                ->gte('low_stock_threshold')
                                ->default(null),
                            Toggle::make('is_active')
                                ->label('Active')
                                ->default(true),
                            Toggle::make('ar_eligible')->live()
                                ->visible(fn (Get $get): bool => $get('../../product_type') === 'frame'),
                            TextInput::make('ar_asset_reference')
                                ->maxLength(255)
                                ->required(fn (Get $get): bool => (bool) $get('ar_eligible'))
                                ->visible(fn (Get $get): bool => $get('../../product_type') === 'frame' && (bool) $get('ar_eligible')),
                            KeyValue::make('attributes')
                                ->columnSpanFull()
                                ->visible(fn (Get $get): bool => $get('../../product_type') !== 'contact_lens'),

                            // Contact-lens specific fields
                            Section::make('Contact Lens Parameters')
                                ->schema([
                                    TextInput::make('attributes.power')
                                        ->label('Power')
                                        ->placeholder('-2.00')
                                        ->maxLength(20),
                                    TextInput::make('attributes.base_curve')
                                        ->label('Base Curve')
                                        ->placeholder('8.6')
                                        ->numeric()
                                        ->minValue(7)
                                        ->maxValue(12),
                                    TextInput::make('attributes.diameter')
                                        ->label('Diameter (mm)')
                                        ->placeholder('14.0')
                                        ->numeric()
                                        ->minValue(10)
                                        ->maxValue(20),
                                    TextInput::make('attributes.cylinder')
                                        ->label('Cylinder')
                                        ->placeholder('-1.25')
                                        ->maxLength(20),
                                    TextInput::make('attributes.axis')
                                        ->label('Axis')
                                        ->placeholder('180')
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(180),
                                    TextInput::make('attributes.add')
                                        ->label('Add')
                                        ->placeholder('+2.00')
                                        ->maxLength(20),
                                    TextInput::make('attributes.color')
                                        ->label('Color')
                                        ->placeholder('Blue')
                                        ->maxLength(50),
                                    TextInput::make('attributes.pack_size')
                                        ->label('Pack Size')
                                        ->placeholder('30')
                                        ->numeric()
                                        ->minValue(1)
                                        ->maxValue(999),
                                ])
                                ->columns(4)
                                ->columnSpanFull()
                                ->visible(fn (Get $get): bool => $get('../../product_type') === 'contact_lens'),
                            FileUpload::make('images')
                                ->disk('public')
                                ->directory('variants')
                                ->visibility('public')
                                ->image()
                                ->multiple()
                                ->reorderable()
                                ->appendFiles()
                                ->maxSize(5120)
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                ->columnSpanFull(),
                        ])
                        ->columns(2),
                ]),
        ]);
    }
}
