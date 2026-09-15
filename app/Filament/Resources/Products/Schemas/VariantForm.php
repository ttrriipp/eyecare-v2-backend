<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\ProductVariant;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

final class VariantForm
{
    /**
     * Return the full variant form schema components.
     *
     * @return array<int, mixed>
     */
    public static function schema(?string $productType = null, ?int $productId = null): array
    {
        return array_merge(
            self::identityFields($productId),
            self::pricingFields(),
            self::typeSpecificFields($productType, $productId),
            self::inventoryFields($productType),
            self::imageField(),
        );
    }

    /**
     * @return array<int, mixed>
     */
    public static function identityFields(?int $productId = null): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn ($rule, $record) => $rule
                        ->where('product_id', $record?->product_id ?? $productId)
                        ->ignore($record?->id),
                ),
            TextInput::make('sku')
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->placeholder('Auto-generated if blank'),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function pricingFields(): array
    {
        return [
            TextInput::make('price')
                ->required()
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->rules(['decimal:0,2'])
                ->prefix('₱')
                ->extraInputAttributes(['class' => 'price-input']),
            TextInput::make('compare_at_price')
                ->label('Compare at Price')
                ->helperText('Previous price shown crossed out to indicate a discount. Must be higher than selling price.')
                ->nullable()
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->rules(['decimal:0,2'])
                ->prefix('₱')
                ->extraInputAttributes(['class' => 'price-input']),
            TextInput::make('cost_price')
                ->label('Cost Price')
                ->helperText('Clinic acquisition cost per unit. Internal only — not visible to patients.')
                ->nullable()
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->rules(['decimal:0,2'])
                ->prefix('₱')
                ->extraInputAttributes(['class' => 'price-input']),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function inventoryFields(?string $productType = null): array
    {
        $isContactLens = $productType === 'contact_lens';

        $stockVisible = $productType !== null
            ? ! $isContactLens
            : fn (Get $get): bool => $get('../../product_type') !== 'contact_lens';

        return [
            TextInput::make('stock_quantity')
                ->label($isContactLens ? 'Stock Quantity' : 'Opening Stock')
                ->helperText($isContactLens
                    ? 'Managed through lot receiving — cannot be edited directly.'
                    : 'Physical quantity on hand at creation. Recorded through Inventory History.')
                ->required(! $isContactLens)
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0)
                ->disabled($isContactLens)
                ->dehydrated(! $isContactLens)
                ->visible($stockVisible),
            TextInput::make('low_stock_threshold')
                ->label('Low Stock Threshold')
                ->helperText('At or below this quantity, the variant is considered low stock. Set to 0 to disable.')
                ->required()
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0),
            TextInput::make('target_stock_level')
                ->label('Target Stock Level')
                ->helperText('Desired quantity after restocking. Used for planning purposes.')
                ->nullable()
                ->numeric()
                ->integer()
                ->minValue(0)
                ->gte('low_stock_threshold')
                ->default(null),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function typeSpecificFields(?string $productType = null, ?int $productId = null): array
    {
        // When productType is known (relation manager), conditionally include.
        if ($productType !== null) {
            return match ($productType) {
                'frame' => [self::frameDimensionsSection()],
                default => [self::genericDetailsField($productId)],
            };
        }

        // When productType is unknown (inline repeater), use visibility closures.
        return [
            self::frameDimensionsSection()
                ->visible(fn (Get $get): bool => $get('../../product_type') === 'frame'),
            self::genericDetailsField($productId)
                ->visible(fn (Get $get): bool => $get('../../product_type') !== 'frame'),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function imageField(): array
    {
        return [
            FileUpload::make('images')
                ->disk((string) config('filesystems.catalog_disk'))
                ->directory('variants')
                ->visibility('public')
                ->image()
                ->multiple()
                ->reorderable()
                ->appendFiles()
                ->maxSize(5120)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->columnSpanFull(),
        ];
    }

    private static function frameDimensionsSection(): Section
    {
        return Section::make('Frame Size & Appearance')
            ->schema([
                TextInput::make('attributes.lens_width')
                    ->label('Lens Width (mm)')
                    ->helperText('Horizontal width of one lens.')
                    ->placeholder('e.g. 52')
                    ->numeric()
                    ->minValue(30)
                    ->maxValue(70),
                TextInput::make('attributes.bridge')
                    ->label('Bridge (mm)')
                    ->helperText('Distance between the lenses above the nose.')
                    ->placeholder('e.g. 18')
                    ->numeric()
                    ->minValue(10)
                    ->maxValue(30),
                TextInput::make('attributes.temple')
                    ->label('Temple Length (mm)')
                    ->helperText('Length of the frame arm.')
                    ->placeholder('e.g. 140')
                    ->numeric()
                    ->minValue(100)
                    ->maxValue(160),
                TextInput::make('attributes.lens_height')
                    ->label('Lens Height (mm)')
                    ->helperText('Vertical lens measurement.')
                    ->placeholder('e.g. 38')
                    ->numeric()
                    ->minValue(20)
                    ->maxValue(60),
                TextInput::make('attributes.color')
                    ->label('Color')
                    ->helperText('Descriptive catalog value (e.g. Tortoise, Black / red).')
                    ->placeholder('e.g. Matte Black')
                    ->maxLength(50),
                TextInput::make('attributes.material')
                    ->label('Material')
                    ->helperText('Frame material (e.g. Acetate, Metal, Titanium).')
                    ->placeholder('e.g. Acetate')
                    ->maxLength(50),
            ])
            ->columns(3)
            ->columnSpanFull();
    }

    private static function contactLensSection(): Section
    {
        return Section::make('Contact Lens Parameters')
            ->schema([
                Select::make('attributes.lens_design')
                    ->label('Lens Design')
                    ->options([
                        'spherical' => 'Spherical',
                        'toric' => 'Toric',
                        'multifocal' => 'Multifocal',
                        'toric_multifocal' => 'Toric Multifocal',
                        'cosmetic' => 'Cosmetic / Plano',
                    ])
                    ->placeholder('Select design')
                    ->live()
                    ->columnSpanFull(),
                TextInput::make('attributes.power')
                    ->label('Power / SPH')
                    ->helperText('Corrective strength in diopters.')
                    ->placeholder('e.g. -3.00')
                    ->maxLength(20),
                TextInput::make('attributes.base_curve')
                    ->label('Base Curve / BC')
                    ->helperText('Lens curvature used for fit.')
                    ->placeholder('e.g. 8.6')
                    ->numeric()
                    ->minValue(7)
                    ->maxValue(12),
                TextInput::make('attributes.diameter')
                    ->label('Diameter / DIA')
                    ->helperText('Overall lens width in mm.')
                    ->placeholder('e.g. 14.0')
                    ->numeric()
                    ->minValue(10)
                    ->maxValue(20),
                TextInput::make('attributes.cylinder')
                    ->label('Cylinder / CYL')
                    ->helperText('Astigmatism correction. Required for toric designs.')
                    ->placeholder('e.g. -1.25')
                    ->maxLength(20)
                    ->visible(fn (Get $get): bool => in_array(
                        $get('attributes.lens_design'),
                        ['toric', 'toric_multifocal'],
                    )),
                TextInput::make('attributes.axis')
                    ->label('Axis')
                    ->helperText('Orientation of cylinder correction (0–180).')
                    ->placeholder('e.g. 180')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(180)
                    ->visible(fn (Get $get): bool => in_array(
                        $get('attributes.lens_design'),
                        ['toric', 'toric_multifocal'],
                    )),
                TextInput::make('attributes.add')
                    ->label('Add')
                    ->helperText('Additional near-vision power. Required for multifocal designs.')
                    ->placeholder('e.g. +2.00')
                    ->maxLength(20)
                    ->visible(fn (Get $get): bool => in_array(
                        $get('attributes.lens_design'),
                        ['multifocal', 'toric_multifocal'],
                    )),
                TextInput::make('attributes.color')
                    ->label('Color')
                    ->helperText('Visible cosmetic tint.')
                    ->maxLength(50),
                TextInput::make('attributes.pack_size')
                    ->label('Pack Size')
                    ->helperText('Number of lenses in one sellable box.')
                    ->placeholder('e.g. 6')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(999),
            ])
            ->columns(4)
            ->columnSpanFull();
    }

    private static function genericDetailsField(?int $productId = null): KeyValue
    {
        $default = null;

        if ($productId !== null) {
            $existingKeys = ProductVariant::query()
                ->where('product_id', $productId)
                ->whereNotNull('attributes')
                ->orderByDesc('id')
                ->limit(1)
                ->value('attributes');

            if (is_array($existingKeys) && count($existingKeys) > 0) {
                $default = array_map(fn () => '', array_filter($existingKeys, fn ($v) => $v !== null));
            }
        }

        return KeyValue::make('attributes')
            ->label('Additional Product Details')
            ->helperText('Product details such as power, base curve, diameter, color, pack size, volume, formulation, etc.')
            ->default($default)
            ->columnSpanFull();
    }
}
