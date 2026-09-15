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
                                ->afterStateUpdated(function (Set $set, ?string $state): void {
                                    if (in_array($state, ['contact_lens', 'accessory'], true)) {
                                        $set('default_variant_attributes', [
                                            ['key' => '', 'value' => ''],
                                        ]);
                                    }
                                })
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

            // ── Default Variant Details (create and edit) ─────────────
            Section::make('Default Variant Details')
                ->columnSpanFull()
                ->description('These values prefill new variants. Changing them later does not update existing variants.')
                ->schema([
                    KeyValue::make('default_variant_attributes')
                        ->label('Default Details')
                        ->helperText('Key/value pairs that will prefill new variants. Examples: base_curve, diameter, pack_size, color, material.')
                        ->columnSpanFull(),
                ])
                ->visible(fn (Get $get): bool => in_array($get('product_type'), ['contact_lens', 'accessory'])),

            Section::make('Default Variant Details')
                ->columnSpanFull()
                ->description('These values prefill new variants. Changing them later does not update existing variants.')
                ->schema([
                    TextInput::make('default_variant_attributes.lens_width')
                        ->label('Lens Width (mm)')
                        ->numeric()
                        ->minValue(30)
                        ->maxValue(70),
                    TextInput::make('default_variant_attributes.bridge')
                        ->label('Bridge (mm)')
                        ->numeric()
                        ->minValue(10)
                        ->maxValue(30),
                    TextInput::make('default_variant_attributes.temple')
                        ->label('Temple Length (mm)')
                        ->numeric()
                        ->minValue(100)
                        ->maxValue(160),
                    TextInput::make('default_variant_attributes.lens_height')
                        ->label('Lens Height (mm)')
                        ->numeric()
                        ->minValue(20)
                        ->maxValue(60),
                    TextInput::make('default_variant_attributes.color')
                        ->label('Color')
                        ->maxLength(50),
                    TextInput::make('default_variant_attributes.material')
                        ->label('Material')
                        ->maxLength(50),
                    KeyValue::make('default_variant_attributes')
                        ->label('Other Details')
                        ->helperText('Additional key/value pairs for frame variants.')
                        ->afterStateHydrated(function (?array $state, KeyValue $component): void {
                            if (empty($state)) {
                                $component->state(['' => '']);
                            }
                        })
                        ->addActionLabel('Add detail')
                        ->columnSpanFull(),
                ])
                ->columns(3)
                ->visible(fn (Get $get): bool => $get('product_type') === 'frame'),

            // ── Inline variants (edit only, full width) ─────────────
            Section::make('Variants')
                ->columnSpanFull()
                ->hiddenOn('create')
                ->schema([
                    Repeater::make('variants')
                        ->relationship()
                        ->minItems(0)
                        ->hiddenLabel()
                        ->schema(VariantForm::schema())
                        ->columns(2),
                ]),
        ]);
    }
}
