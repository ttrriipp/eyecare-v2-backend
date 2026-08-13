<?php

namespace App\Filament\Resources\Quotations\Schemas;

use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\ProductVariant;
use App\Models\Service;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class QuotationCreationForm
{
    /**
     * @return array<int, Section>
     */
    public static function components(
        ?Closure $patientIdResolver = null,
        ?Closure $prescriptionEyewearResolver = null,
    ): array {
        $patientIdResolver ??= fn (Get $get): ?int => filled($get('patient_id'))
            ? (int) $get('patient_id')
            : null;

        $prescriptionEyewearResolver ??= fn (Get $get): bool => collect(
            $get('items') ?? $get('../../items') ?? [],
        )->contains(fn (array $item): bool => ($item['item_kind'] ?? null) === 'lens');

        return [
            Section::make('Quotation Details')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        DatePicker::make('valid_until')
                            ->label('Valid Until')
                            ->native(false)
                            ->minDate(today())
                            ->suffixIcon('heroicon-o-calendar-days'),
                        TextInput::make('discount_amount')
                            ->label('Discount')
                            ->prefix('₱')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->default(0)
                            ->disabled(fn (): bool => auth()->user()?->isAdmin() !== true)
                            ->dehydrated()
                            ->live(onBlur: true),
                    ]),
                ]),

            Section::make('Prescription Eyewear Build')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 3])->schema([
                        Placeholder::make('frame_selection')
                            ->label('Frame')
                            ->content(fn (Get $get): string => collect($get('items') ?? [])
                                ->contains(fn (array $item): bool => (
                                    (($item['item_kind'] ?? null) === 'catalog'
                                        && ($item['catalog_product_type'] ?? null) === 'frame')
                                    || ($prescriptionEyewearResolver($get)
                                        && ($item['item_kind'] ?? null) === 'custom_product')
                                ))
                                ? 'Selected ✓'
                                : 'Not selected'),
                        Placeholder::make('lens_package_selection')
                            ->label('Lens package')
                            ->content(fn (Get $get): string => collect($get('items') ?? [])
                                ->contains(fn (array $item): bool => ($item['item_kind'] ?? null) === 'lens')
                                ? 'Selected ✓'
                                : 'Not selected'),
                        Placeholder::make('lens_options_selection')
                            ->label('Lens options')
                            ->content(fn (Get $get): string => sprintf(
                                '%d selected',
                                collect($get('items') ?? [])->filter(
                                    fn (array $item): bool => ($item['item_kind'] ?? null) === 'lens_option',
                                )->count(),
                            )),
                    ]),
                ])
                ->visible(fn (Get $get): bool => $prescriptionEyewearResolver($get)),

            Section::make('Items')
                ->schema([
                    Repeater::make('items')
                        ->hiddenLabel()
                        ->itemLabel(fn (array $state): string => filled($state['description'] ?? null)
                            ? Str::limit($state['description'], 60)
                            : 'New quotation item')
                        ->collapsible()
                        ->schema([
                            Grid::make(['default' => 1, 'md' => 2])
                                ->columnSpanFull()
                                ->schema([
                                    Select::make('item_kind')
                                        ->label('Item Type')
                                        ->options(fn (Get $get): array => $prescriptionEyewearResolver($get)
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
                                            ])
                                        ->default('catalog')
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, Get $get, mixed $state) use ($prescriptionEyewearResolver): void {
                                            $set('product_variant_id', null);
                                            $set('lens_category_id', null);
                                            $set('lens_option_id', null);
                                            $set('service_id', null);
                                            $set('catalog_product_type', null);
                                            $set('description', null);
                                            $set('unit_price', null);
                                            $set('line_total', null);

                                            if ($prescriptionEyewearResolver($get) && $state === 'custom_product') {
                                                $set('quantity', 1);
                                            }
                                        }),
                                    Hidden::make('catalog_product_type')
                                        ->dehydrated(false),
                                    Select::make('product_variant_id')
                                        ->label(fn (Get $get): string => $prescriptionEyewearResolver($get)
                                            ? 'Frame'
                                            : 'Catalog Item')
                                        ->options(fn (Get $get): array => ProductVariant::query()
                                            ->with('product')
                                            ->active()
                                            ->whereHas('product', fn (Builder $query): Builder => $query->where('is_active', true))
                                            ->when(
                                                $prescriptionEyewearResolver($get),
                                                fn (Builder $query): Builder => $query->whereHas(
                                                    'product',
                                                    fn (Builder $productQuery): Builder => $productQuery->where('product_type', 'frame'),
                                                ),
                                            )
                                            ->orderBy('sku')
                                            ->get()
                                            ->mapWithKeys(fn (ProductVariant $variant): array => [
                                                $variant->id => "{$variant->product->name} — {$variant->name} ({$variant->sku})",
                                            ])
                                            ->all())
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
                                    Select::make('lens_category_id')
                                        ->label(fn (Get $get): string => $prescriptionEyewearResolver($get)
                                            ? 'Lens Package'
                                            : 'Lens Category')
                                        ->options(fn (): array => LensCategory::query()
                                            ->orderBy('name')
                                            ->pluck('name', 'id')
                                            ->all())
                                        ->searchable()
                                        ->preload()
                                        ->required(fn (Get $get): bool => $get('item_kind') === 'lens')
                                        ->visible(fn (Get $get): bool => $get('item_kind') === 'lens')
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                            $lensCategory = LensCategory::query()->find($state);

                                            if ($lensCategory === null) {
                                                return;
                                            }

                                            $set('description', $lensCategory->name);
                                            $set('quantity', 1);

                                            if ($lensCategory->price !== null) {
                                                $set('unit_price', $lensCategory->price);
                                                $set('line_total', number_format(
                                                    ((float) ($get('quantity') ?? 1)) * ((float) $lensCategory->price),
                                                    2,
                                                ));
                                            }
                                        }),
                                    Select::make('lens_option_id')
                                        ->label('Lens Option')
                                        ->options(fn (): array => LensOption::query()
                                            ->active()
                                            ->orderBy('name')
                                            ->pluck('name', 'id')
                                            ->all())
                                        ->searchable()
                                        ->preload()
                                        ->required(fn (Get $get): bool => $get('item_kind') === 'lens_option')
                                        ->visible(fn (Get $get): bool => $get('item_kind') === 'lens_option')
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                            $lensOption = LensOption::query()->active()->find($state);

                                            if ($lensOption === null) {
                                                return;
                                            }

                                            $set('description', $lensOption->name);
                                            $set('unit_price', $lensOption->price);
                                            $set('quantity', 1);
                                            $set('line_total', number_format(
                                                ((float) ($get('quantity') ?? 1)) * ((float) $lensOption->price),
                                                2,
                                            ));
                                        }),
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
                                ]),
                            TextInput::make('description')
                                ->label('Description')
                                ->required(fn (Get $get): bool => in_array($get('item_kind'), [
                                    'custom_product',
                                    'custom_service',
                                ], true))
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => in_array($get('item_kind'), [
                                    'custom_product',
                                    'custom_service',
                                ], true))
                                ->disabled(fn (Get $get): bool => self::usesCatalogValues($get))
                                ->dehydrated()
                                ->dehydratedWhenHidden()
                                ->columnSpanFull(),
                            TextInput::make('quantity')
                                ->label('Quantity')
                                ->required()
                                ->integer()
                                ->minValue(1)
                                ->maxValue(fn (Get $get): int => self::hasFixedQuantity($get, $prescriptionEyewearResolver) ? 1 : 999)
                                ->default(1)
                                ->disabled(fn (Get $get): bool => self::hasFixedQuantity($get, $prescriptionEyewearResolver))
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
                        ->defaultItems(1)
                        ->minItems(1)
                        ->maxItems(50)
                        ->addActionLabel('Add Item'),
                ]),

            Section::make('Summary and Notes')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 3])->schema([
                        Placeholder::make('estimated_subtotal')
                            ->label('Subtotal')
                            ->content(fn (Get $get): string => '₱'.number_format(self::subtotal($get), 2)),
                        Placeholder::make('estimated_discount')
                            ->label('Discount')
                            ->content(fn (Get $get): string => '₱'.number_format((float) ($get('discount_amount') ?? 0), 2)),
                        Placeholder::make('estimated_total')
                            ->label('Estimated Total')
                            ->content(fn (Get $get): string => '₱'.number_format(
                                max(self::subtotal($get) - (float) ($get('discount_amount') ?? 0), 0),
                                2,
                            )),
                    ]),
                    Textarea::make('notes')
                        ->label('Patient Notes')
                        ->maxLength(2000)
                        ->columnSpanFull(),
                ]),
        ];
    }

    private static function usesCatalogValues(Get $get): bool
    {
        return in_array($get('item_kind'), ['catalog', 'lens', 'lens_option', 'service'], true);
    }

    private static function hasFixedQuantity(Get $get, Closure $prescriptionEyewearResolver): bool
    {
        return in_array($get('item_kind'), ['lens', 'lens_option'], true)
            || ($get('item_kind') === 'catalog' && $get('catalog_product_type') === 'frame')
            || ($prescriptionEyewearResolver($get) && $get('item_kind') === 'custom_product');
    }

    private static function subtotal(Get $get): float
    {
        return collect($get('items') ?? [])->sum(
            fn (array $item): float => ((float) ($item['quantity'] ?? 0))
                * ((float) ($item['unit_price'] ?? 0)),
        );
    }
}
