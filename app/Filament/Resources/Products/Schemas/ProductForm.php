<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
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
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            // ── Top row: main (2/3) + sidebar (1/3) ──────────────────
            Grid::make(3)->schema([
                Section::make('Product Details')
                    ->columnSpan(2)
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

                // ── Sidebar ──────────────────────────────────────────
                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make('Status')->schema([
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

            // ── Images (full width) ───────────────────────────────────
            Section::make('Images')->columnSpanFull()->schema([
                Repeater::make('images')
                    ->relationship()
                    ->defaultItems(0)
                    ->hiddenLabel()
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
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->required(),
                        Placeholder::make('preview')
                            ->label('Current Image')
                            ->content(fn ($record) => $record?->path
                                ? new HtmlString(
                                    '<img src="'.asset('storage/'.$record->path).'" style="max-width:100%;border-radius:6px;" />'
                                )
                                : '—'
                            ),
                        Toggle::make('is_primary')->default(false),
                        TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                    ])
                    ->columns(3),
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
                            Toggle::make('ar_eligible')->live(),
                            TextInput::make('ar_asset_reference')
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => (bool) $get('ar_eligible')),
                            KeyValue::make('dimensions')->columnSpanFull(),
                        ])
                        ->columns(2),
                ]),
        ]);
    }
}
