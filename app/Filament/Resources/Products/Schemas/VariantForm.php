<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
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
    public static function schema(?string $productType = null): array
    {
        return array_merge(
            self::identityFields(),
            self::pricingFields(),
            self::typeSpecificFields($productType),
            self::inventoryFields($productType),
            self::imageField(),
        );
    }

    /**
     * @return array<int, mixed>
     */
    public static function identityFields(): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn ($rule, $record) => $rule
                        ->where('product_id', $record?->product_id)
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
                ->helperText('Original or reference price shown crossed out to indicate a discount. Must exceed selling price.')
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

        return [
            TextInput::make('stock_quantity')
                ->label($isContactLens ? 'Stock Quantity' : 'Opening Stock')
                ->helperText($isContactLens
                    ? 'Managed through lot receiving — cannot be edited directly.'
                    : 'Physical quantity on hand at creation. Recorded through Inventory History.')
                ->required(! $isContactLens)
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->disabled($isContactLens)
                ->dehydrated(! $isContactLens)
                ->visible(! $isContactLens),
            TextInput::make('low_stock_threshold')
                ->label('Low Stock Threshold')
                ->helperText('At or below this quantity, the variant needs reorder attention. Set to 0 to disable.')
                ->required()
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0),
            TextInput::make('target_stock_level')
                ->label('Target Stock Level')
                ->helperText('Desired quantity after restocking. Used to calculate suggested reorder quantity.')
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
    public static function typeSpecificFields(?string $productType = null): array
    {
        return match ($productType) {
            'frame' => [self::frameDimensionsSection()],
            'contact_lens' => [self::contactLensSection()],
            default => [self::accessoryAttributesField()],
        };
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
                    ->numeric()
                    ->minValue(30)
                    ->maxValue(70),
                TextInput::make('attributes.bridge')
                    ->label('Bridge (mm)')
                    ->helperText('Distance between the lenses above the nose.')
                    ->numeric()
                    ->minValue(10)
                    ->maxValue(30),
                TextInput::make('attributes.temple')
                    ->label('Temple (mm)')
                    ->helperText('Length of the frame arm.')
                    ->numeric()
                    ->minValue(100)
                    ->maxValue(160),
                TextInput::make('attributes.lens_height')
                    ->label('Lens Height (mm)')
                    ->helperText('Vertical lens measurement.')
                    ->numeric()
                    ->minValue(20)
                    ->maxValue(60),
                TextInput::make('attributes.color')
                    ->label('Color')
                    ->helperText('Descriptive catalog value (e.g., Tortoise, Black / red).')
                    ->maxLength(50),
                TextInput::make('attributes.material')
                    ->label('Material')
                    ->helperText('Frame material (e.g., Acetate, Metal, Titanium).')
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
                    ->helperText('Corrective strength in diopters (e.g., -3.00).')
                    ->maxLength(20),
                TextInput::make('attributes.base_curve')
                    ->label('Base Curve / BC')
                    ->helperText('Lens curvature used for fit (e.g., 8.6).')
                    ->numeric()
                    ->minValue(7)
                    ->maxValue(12),
                TextInput::make('attributes.diameter')
                    ->label('Diameter / DIA')
                    ->helperText('Overall lens width in mm (e.g., 14.0).')
                    ->numeric()
                    ->minValue(10)
                    ->maxValue(20),
                TextInput::make('attributes.cylinder')
                    ->label('Cylinder / CYL')
                    ->helperText('Astigmatism correction. Required for toric designs.')
                    ->maxLength(20)
                    ->visible(fn (Get $get): bool => in_array(
                        $get('attributes.lens_design'),
                        ['toric', 'toric_multifocal'],
                    )),
                TextInput::make('attributes.axis')
                    ->label('Axis')
                    ->helperText('Orientation of cylinder correction (0–180).')
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
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(999),
            ])
            ->columns(4)
            ->columnSpanFull();
    }

    private static function accessoryAttributesField(): KeyValue
    {
        return KeyValue::make('attributes')
            ->label('Additional Product Details')
            ->helperText('Optional facts describing this product. Suggested keys: Volume, Package, Formulation, Compatible with.')
            ->columnSpanFull();
    }
}
