<?php

namespace App\Filament\Resources\OpticalOrders\Schemas;

use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\ProductVariant;
use App\Models\Service;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class OpticalOrderCreationForm
{
    public static function prescriptionEyewearSection(Closure $prescriptionEyewearResolver): Section
    {
        return Section::make('Prescription Eyewear')
            ->schema([
                Fieldset::make('1. Frame — optional')
                    ->schema([
                        Select::make('eyewear_frame_source')
                            ->label('Source')
                            ->options([
                                'catalog' => 'Catalog frame',
                                'patient' => 'Patient-supplied frame',
                            ])
                            ->placeholder('No frame selected')
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state === 'catalog') {
                                    $set('eyewear_patient_frame_description', null);
                                    $set('eyewear_patient_frame_price', null);
                                }

                                if ($state === 'patient') {
                                    $set('eyewear_frame_variant_id', null);
                                }
                            })
                            ->columnSpanFull(),
                        Select::make('eyewear_frame_variant_id')
                            ->label('Frame')
                            ->options(fn (): array => ProductVariant::query()
                                ->with('product')
                                ->active()
                                ->whereHas('product', fn (Builder $query): Builder => $query
                                    ->where('is_active', true)
                                    ->where('product_type', 'frame'))
                                ->orderBy('sku')
                                ->get()
                                ->mapWithKeys(fn (ProductVariant $variant): array => [
                                    $variant->id => "{$variant->product->name} — {$variant->name}",
                                ])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required(fn (Get $get): bool => $get('eyewear_frame_source') === 'catalog')
                            ->visible(fn (Get $get): bool => $get('eyewear_frame_source') === 'catalog')
                            ->live()
                            ->columnSpan(2),
                        Placeholder::make('eyewear_frame_item_code')
                            ->label('Item code')
                            ->content(fn (Get $get): string => self::frameItemCode($get))
                            ->visible(fn (Get $get): bool => $get('eyewear_frame_source') === 'catalog'),
                        Placeholder::make('eyewear_frame_price')
                            ->label('Price')
                            ->content(fn (Get $get): string => self::framePrice($get))
                            ->visible(fn (Get $get): bool => $get('eyewear_frame_source') === 'catalog'),
                        TextInput::make('eyewear_patient_frame_description')
                            ->label('Frame description')
                            ->required(fn (Get $get): bool => $get('eyewear_frame_source') === 'patient')
                            ->visible(fn (Get $get): bool => $get('eyewear_frame_source') === 'patient')
                            ->maxLength(255)
                            ->columnSpan(2),
                        TextInput::make('eyewear_patient_frame_price')
                            ->label('Price')
                            ->prefix('₱')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->required(fn (Get $get): bool => $get('eyewear_frame_source') === 'patient')
                            ->visible(fn (Get $get): bool => $get('eyewear_frame_source') === 'patient'),
                    ])
                    ->columns(['default' => 1, 'md' => 3])
                    ->columnSpanFull(),
                Fieldset::make('2. Lens Package — required')
                    ->schema([
                        Select::make('eyewear_lens_category_id')
                            ->label('Package')
                            ->options(fn (): array => LensCategory::query()
                                ->whereNotNull('price')
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->columnSpan(2),
                        Placeholder::make('eyewear_lens_quantity')
                            ->label('Quantity')
                            ->content('1 pair'),
                        Placeholder::make('eyewear_lens_price')
                            ->label('Price')
                            ->content(fn (Get $get): string => self::lensPackagePrice($get)),
                    ])
                    ->columns(['default' => 1, 'md' => 4])
                    ->columnSpanFull(),
                Fieldset::make('3. Lens Options — optional')
                    ->schema([
                        Repeater::make('eyewear_lens_options')
                            ->hiddenLabel()
                            ->schema([
                                Select::make('lens_option_id')
                                    ->label('Lens option')
                                    ->options(fn (): array => LensOption::query()
                                        ->active()
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all())
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->columnSpan(2),
                                Placeholder::make('lens_option_price')
                                    ->label('Price')
                                    ->content(fn (Get $get): string => self::lensOptionPrice($get)),
                            ])
                            ->columns(['default' => 1, 'md' => 3])
                            ->defaultItems(0)
                            ->minItems(0)
                            ->maxItems(20)
                            ->addActionLabel('Add lens option')
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                Grid::make(['default' => 1, 'md' => 3])
                    ->schema([
                        Placeholder::make('eyewear_subtotal')
                            ->label('Eyewear subtotal')
                            ->content(fn (Get $get): string => '₱'.number_format(self::eyewearSubtotal($get), 2))
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ])
            ->visible(fn (Get $get): bool => $prescriptionEyewearResolver($get));
    }

    public static function itemsSection(
        Closure $prescriptionEyewearResolver,
        bool $dedicatedPrescriptionEyewear = false,
        bool $includeServices = true,
        bool $excludeFramesFromOtherItems = false,
    ): Section {
        $customItemKinds = $includeServices
            ? ['custom_product', 'custom_service']
            : ['custom'];

        return Section::make(fn (Get $get): string => $dedicatedPrescriptionEyewear
            && $prescriptionEyewearResolver($get) ? 'Other Items' : 'Items')
            ->schema([
                Repeater::make('items')
                    ->hiddenLabel()
                    ->itemLabel(fn (array $state): string => filled($state['description'] ?? null)
                        ? Str::limit($state['description'], 60)
                        : 'New item')
                    ->collapsible()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])
                            ->columnSpanFull()
                            ->schema([
                                Select::make('item_kind')
                                    ->label('Item Type')
                                    ->options(fn (Get $get): array => self::itemKindOptions(
                                        $get,
                                        $prescriptionEyewearResolver,
                                        $dedicatedPrescriptionEyewear,
                                        $includeServices,
                                    ))
                                    ->default($includeServices ? 'catalog' : 'catalog')
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, Get $get, mixed $state) use ($prescriptionEyewearResolver, $dedicatedPrescriptionEyewear): void {
                                        $set('product_variant_id', null);
                                        $set('lens_category_id', null);
                                        $set('lens_option_id', null);
                                        $set('service_id', null);
                                        $set('catalog_product_type', null);
                                        $set('description', null);
                                        $set('unit_price', null);
                                        $set('line_total', null);

                                        if ($prescriptionEyewearResolver($get)
                                            && ! $dedicatedPrescriptionEyewear
                                            && $state === 'custom_product') {
                                            $set('quantity', 1);
                                        }
                                    }),
                                Hidden::make('catalog_product_type')
                                    ->dehydrated(false),
                                Select::make('product_variant_id')
                                    ->label(fn (Get $get): string => $prescriptionEyewearResolver($get)
                                        && ! $dedicatedPrescriptionEyewear ? 'Frame' : 'Catalog Item')
                                    ->options(fn (Get $get): array => self::productVariantOptions(
                                        $get,
                                        $prescriptionEyewearResolver,
                                        $dedicatedPrescriptionEyewear,
                                        $excludeFramesFromOtherItems,
                                    ))
                                    ->searchable()
                                    ->preload()
                                    ->required(fn (Get $get): bool => $get('item_kind') === 'catalog')
                                    ->visible(fn (Get $get): bool => $get('item_kind') === 'catalog')
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                        $variant = ProductVariant::query()->with('product')->find($state);

                                        if ($variant === null) {
                                            return;
                                        }

                                        $set('catalog_product_type', $variant->product->product_type);
                                        $set('description', "{$variant->product->name} — {$variant->name}");
                                        $set('unit_price', $variant->price);

                                        if ($variant->product->product_type === 'frame') {
                                            $set('quantity', 1);
                                        }

                                        $set('line_total', number_format(
                                            ((float) ($get('quantity') ?? 1)) * ((float) $variant->price),
                                            2,
                                        ));
                                    }),
                                ...($includeServices ? [
                                    Select::make('service_id')
                                        ->label('Service')
                                        ->options(fn (): array => Service::query()
                                            ->active()
                                            ->orderBy('name')
                                            ->pluck('name', 'id')
                                            ->all())
                                        ->searchable()
                                        ->preload()
                                        ->required(fn (Get $get): bool => $get('item_kind') === 'service')
                                        ->visible(fn (Get $get): bool => $get('item_kind') === 'service')
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                            $service = Service::query()->find($state);

                                            if ($service === null) {
                                                return;
                                            }

                                            $set('description', $service->name);
                                            $set('unit_price', $service->price);
                                            $set('line_total', number_format(
                                                ((float) ($get('quantity') ?? 1)) * ((float) $service->price),
                                                2,
                                            ));
                                        }),
                                ] : []),
                            ]),
                        TextInput::make('description')
                            ->label('Description')
                            ->required(fn (Get $get): bool => in_array($get('item_kind'), $customItemKinds, true))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => in_array($get('item_kind'), $customItemKinds, true))
                            ->disabled(fn (Get $get): bool => self::usesCatalogValues($get))
                            ->dehydrated()
                            ->dehydratedWhenHidden()
                            ->columnSpanFull(),
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(fn (Get $get): int => self::hasFixedQuantity($get, $prescriptionEyewearResolver, $dedicatedPrescriptionEyewear) ? 1 : 999)
                            ->default(1)
                            ->disabled(fn (Get $get): bool => self::hasFixedQuantity($get, $prescriptionEyewearResolver, $dedicatedPrescriptionEyewear))
                            ->dehydrated()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                $set('line_total', number_format(
                                    ((float) ($get('quantity') ?? 0)) * ((float) ($get('unit_price') ?? 0)),
                                    2,
                                ));
                            }),
                        TextInput::make('unit_price')
                            ->label('Unit Price')
                            ->prefix('₱')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->disabled(fn (Get $get): bool => self::usesCatalogValues($get))
                            ->dehydrated()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                $set('line_total', number_format(
                                    ((float) ($get('quantity') ?? 0)) * ((float) ($get('unit_price') ?? 0)),
                                    2,
                                ));
                            }),
                        TextInput::make('line_total')
                            ->label('Line Total')
                            ->prefix('₱')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(['default' => 1, 'md' => 3])
                    ->defaultItems(fn (Get $get): int => $dedicatedPrescriptionEyewear
                        && $prescriptionEyewearResolver($get) ? 0 : 1)
                    ->minItems(fn (Get $get): int => $dedicatedPrescriptionEyewear
                        && $prescriptionEyewearResolver($get) ? 0 : 1)
                    ->maxItems(50)
                    ->addActionLabel(fn (Get $get): string => $dedicatedPrescriptionEyewear
                        && $prescriptionEyewearResolver($get) ? 'Add Other Item' : 'Add Item'),
            ]);
    }

    public static function subtotal(
        Get $get,
        Closure $prescriptionEyewearResolver,
        bool $dedicatedPrescriptionEyewear,
    ): float {
        $otherItemsSubtotal = collect($get('items') ?? [])->sum(
            fn (array $item): float => ((float) ($item['quantity'] ?? 0))
                * ((float) ($item['unit_price'] ?? 0)),
        );

        return $otherItemsSubtotal + ($dedicatedPrescriptionEyewear && $prescriptionEyewearResolver($get)
            ? self::eyewearSubtotal($get)
            : 0);
    }

    private static function itemKindOptions(
        Get $get,
        Closure $prescriptionEyewearResolver,
        bool $dedicatedPrescriptionEyewear,
        bool $includeServices,
    ): array {
        if (! $includeServices) {
            return [
                'catalog' => 'Catalog item',
                'custom' => 'Custom item',
            ];
        }

        return $prescriptionEyewearResolver($get) && ! $dedicatedPrescriptionEyewear
            ? [
                'catalog' => 'Catalog Frame',
                'custom_product' => 'Patient-supplied Frame',
                'lens' => 'Lens Package',
                'lens_option' => 'Lens Option',
                'service' => 'Service',
                'custom_service' => 'Custom Service',
            ]
            : [
                'catalog' => 'Catalog Item',
                'service' => 'Service',
                'custom_product' => 'Custom Item',
                'custom_service' => 'Custom Service',
            ];
    }

    private static function productVariantOptions(
        Get $get,
        Closure $prescriptionEyewearResolver,
        bool $dedicatedPrescriptionEyewear,
        bool $excludeFramesFromOtherItems,
    ): array {
        return ProductVariant::query()
            ->with('product')
            ->active()
            ->whereHas('product', fn (Builder $query): Builder => $query->where('is_active', true))
            ->when(
                $prescriptionEyewearResolver($get) && ! $dedicatedPrescriptionEyewear,
                fn (Builder $query): Builder => $query->whereHas(
                    'product',
                    fn (Builder $productQuery): Builder => $productQuery->where('product_type', 'frame'),
                ),
            )
            ->when(
                $excludeFramesFromOtherItems && $prescriptionEyewearResolver($get),
                fn (Builder $query): Builder => $query->whereHas(
                    'product',
                    fn (Builder $productQuery): Builder => $productQuery->where('product_type', '!=', 'frame'),
                ),
            )
            ->orderBy('sku')
            ->get()
            ->mapWithKeys(fn (ProductVariant $variant): array => [
                $variant->id => "{$variant->product->name} — {$variant->name} ({$variant->sku})",
            ])
            ->all();
    }

    private static function frameItemCode(Get $get): string
    {
        return self::frameVariant($get)?->sku ?? '—';
    }

    private static function framePrice(Get $get): string
    {
        $variant = self::frameVariant($get);

        return $variant === null ? '—' : '₱'.number_format((float) $variant->price, 2);
    }

    private static function frameVariant(Get $get): ?ProductVariant
    {
        $variantId = $get('eyewear_frame_variant_id');

        return filled($variantId)
            ? ProductVariant::query()->with('product')->find((int) $variantId)
            : null;
    }

    private static function lensPackagePrice(Get $get): string
    {
        $lensCategoryId = $get('eyewear_lens_category_id');
        $lensCategory = filled($lensCategoryId)
            ? LensCategory::query()->find((int) $lensCategoryId)
            : null;

        return $lensCategory?->price === null
            ? '—'
            : '₱'.number_format((float) $lensCategory->price, 2);
    }

    private static function lensOptionPrice(Get $get): string
    {
        $lensOptionId = $get('lens_option_id');
        $lensOption = filled($lensOptionId)
            ? LensOption::query()->active()->find((int) $lensOptionId)
            : null;

        return $lensOption?->price === null
            ? '—'
            : '₱'.number_format((float) $lensOption->price, 2);
    }

    private static function eyewearSubtotal(Get $get): float
    {
        $framePrice = filled($get('eyewear_patient_frame_price'))
            ? (float) $get('eyewear_patient_frame_price')
            : (float) (self::frameVariant($get)?->price ?? 0);
        $lensCategoryId = $get('eyewear_lens_category_id');
        $lensPrice = filled($lensCategoryId)
            ? (float) (LensCategory::query()->find((int) $lensCategoryId)?->price ?? 0)
            : 0;
        $optionPrice = collect($get('eyewear_lens_options') ?? [])->sum(function (array $option): float {
            $lensOption = filled($option['lens_option_id'] ?? null)
                ? LensOption::query()->active()->find((int) $option['lens_option_id'])
                : null;

            return (float) ($lensOption?->price ?? 0);
        });

        return (float) $framePrice + $lensPrice + $optionPrice;
    }

    private static function usesCatalogValues(Get $get): bool
    {
        return in_array($get('item_kind'), ['catalog', 'lens', 'lens_option', 'service'], true);
    }

    private static function hasFixedQuantity(
        Get $get,
        Closure $prescriptionEyewearResolver,
        bool $dedicatedPrescriptionEyewear,
    ): bool {
        return in_array($get('item_kind'), ['lens', 'lens_option'], true)
            || ($get('item_kind') === 'catalog' && $get('catalog_product_type') === 'frame')
            || ($prescriptionEyewearResolver($get)
                && ! $dedicatedPrescriptionEyewear
                && $get('item_kind') === 'custom_product');
    }
}
