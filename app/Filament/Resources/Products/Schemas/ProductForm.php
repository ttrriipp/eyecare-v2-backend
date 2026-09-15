<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
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
                            ->disk((string) config('filesystems.catalog_disk'))
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
                        ->schema(VariantForm::schema())
                        ->columns(2),
                ]),
        ]);
    }
}
