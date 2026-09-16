<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductUsage;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductForm
{
    /** @var array<int, string> */
    private const array FRAME_ATTRIBUTE_KEYS = [
        'lens_width',
        'bridge',
        'temple',
        'lens_height',
        'color',
        'material',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareFormDataBeforeFill(array $data): array
    {
        $attributes = is_array($data['default_variant_attributes'] ?? null)
            ? $data['default_variant_attributes']
            : [];
        $productType = $data['product_type'] ?? null;

        if ($productType === 'frame') {
            $data['frame_default_attributes'] = Arr::only($attributes, self::FRAME_ATTRIBUTE_KEYS);
            $data['frame_other_details'] = self::toKeyValueRows(
                Arr::except($attributes, self::FRAME_ATTRIBUTE_KEYS),
            );
            $data['generic_default_details'] = [];
        } elseif (in_array($productType, ['contact_lens', 'accessory'], true)) {
            $data['generic_default_details'] = self::toKeyValueRows($attributes);
            $data['frame_default_attributes'] = [];
            $data['frame_other_details'] = [];
        }

        unset($data['default_variant_attributes']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareFormDataBeforeSave(array $data): array
    {
        $productType = $data['product_type'] ?? null;

        if ($productType === 'frame') {
            $frameAttributes = is_array($data['frame_default_attributes'] ?? null)
                ? array_filter(
                    $data['frame_default_attributes'],
                    fn (mixed $value): bool => filled($value),
                )
                : [];

            $data['default_variant_attributes'] = array_merge(
                self::toKeyValueMap($data['frame_other_details'] ?? []),
                $frameAttributes,
            );
        } elseif (in_array($productType, ['contact_lens', 'accessory'], true)) {
            $data['default_variant_attributes'] = self::toKeyValueMap(
                $data['generic_default_details'] ?? [],
            );
        }

        if (! self::isUsageTrackedProduct($productType)) {
            $data['usage'] = null;
        }

        unset(
            $data['generic_default_details'],
            $data['frame_default_attributes'],
            $data['frame_other_details'],
        );

        return $data;
    }

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
                                    if ($state === 'frame') {
                                        $set('usage', null);
                                        $set('frame_default_attributes', []);
                                        $set('frame_other_details', self::emptyKeyValueRows());
                                        $set('generic_default_details', []);

                                        return;
                                    }

                                    if (in_array($state, ['contact_lens', 'accessory'], true)) {
                                        $set('default_variant_attributes', []);
                                        $set('generic_default_details', self::emptyKeyValueRows());
                                        $set('frame_default_attributes', []);
                                        $set('frame_other_details', []);

                                        return;
                                    }

                                    $set('usage', null);
                                })
                                ->disabledOn('edit')
                                ->dehydrated()
                                ->columnSpanFull(),
                            Select::make('usage')
                                ->label('Usage')
                                ->options(ProductUsage::options())
                                ->helperText('How long the product is used before replacement or replenishment.')
                                ->nullable()
                                ->rules([Rule::enum(ProductUsage::class)])
                                ->visible(fn (Get $get): bool => self::isUsageTrackedProduct($get('product_type')))
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
                ->collapsible()
                ->description('These values prefill new variants. Changing them later does not update existing variants.')
                ->schema([
                    KeyValue::make('generic_default_details')
                        ->label('Default Details')
                        ->helperText('Key/value pairs that will prefill new variants. Examples: base_curve, diameter, pack_size, color, material.')
                        ->afterStateHydrated(function (?array $state, KeyValue $component): void {
                            if (empty($state)) {
                                $component->state(self::emptyKeyValueRows());
                            }
                        })
                        ->dehydratedWhenHidden()
                        ->columnSpanFull(),
                ])
                ->visible(fn (Get $get): bool => in_array($get('product_type'), ['contact_lens', 'accessory'])),

            Section::make('Default Variant Details')
                ->columnSpanFull()
                ->collapsible()
                ->description('These values prefill new variants. Changing them later does not update existing variants.')
                ->schema([
                    TextInput::make('frame_default_attributes.lens_width')
                        ->label('Lens Width (mm)')
                        ->placeholder('e.g. 52')
                        ->numeric()
                        ->minValue(30)
                        ->maxValue(70),
                    TextInput::make('frame_default_attributes.bridge')
                        ->label('Bridge (mm)')
                        ->placeholder('e.g. 18')
                        ->numeric()
                        ->minValue(10)
                        ->maxValue(30),
                    TextInput::make('frame_default_attributes.temple')
                        ->label('Temple Length (mm)')
                        ->placeholder('e.g. 140')
                        ->numeric()
                        ->minValue(100)
                        ->maxValue(160),
                    TextInput::make('frame_default_attributes.lens_height')
                        ->label('Lens Height (mm)')
                        ->placeholder('e.g. 38')
                        ->numeric()
                        ->minValue(20)
                        ->maxValue(60),
                    Select::make('frame_default_attributes.color')
                        ->label('Color')
                        ->options(config('catalog.variant_presets.colors'))
                        ->searchable(),
                    Select::make('frame_default_attributes.material')
                        ->label('Material')
                        ->options(config('catalog.variant_presets.materials'))
                        ->searchable(),
                    KeyValue::make('frame_other_details')
                        ->label('Other Details')
                        ->helperText('Additional key/value pairs for frame variants.')
                        ->afterStateHydrated(function (?array $state, KeyValue $component): void {
                            if (empty($state)) {
                                $component->state(self::emptyKeyValueRows());
                            }
                        })
                        ->dehydratedWhenHidden()
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

    /**
     * @return array<int, array{key: string, value: string}>
     */
    private static function emptyKeyValueRows(): array
    {
        return [
            ['key' => '', 'value' => ''],
        ];
    }

    private static function isUsageTrackedProduct(mixed $productType): bool
    {
        return is_string($productType)
            && in_array($productType, Product::USAGE_TRACKED_TYPES, true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, array{key: string, value: mixed}>
     */
    private static function toKeyValueRows(array $attributes): array
    {
        return array_map(
            fn (mixed $value, string|int $key): array => [
                'key' => (string) $key,
                'value' => $value,
            ],
            $attributes,
            array_keys($attributes),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function toKeyValueMap(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        if (
            array_is_list($state)
            && (blank($state) || (is_array($state[0] ?? null) && array_key_exists('key', $state[0])))
        ) {
            $map = [];

            foreach ($state as $row) {
                if (! is_array($row) || blank($row['key'] ?? null)) {
                    continue;
                }

                $map[(string) $row['key']] = $row['value'] ?? null;
            }

            return $map;
        }

        return collect($state)
            ->filter(fn (mixed $value, string|int $key): bool => filled($key))
            ->mapWithKeys(fn (mixed $value, string|int $key): array => [(string) $key => $value])
            ->all();
    }
}
