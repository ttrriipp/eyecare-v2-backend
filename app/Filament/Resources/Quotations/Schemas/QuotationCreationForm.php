<?php

namespace App\Filament\Resources\Quotations\Schemas;

use App\Models\LensCategory;
use App\Models\ProductVariant;
use Filament\Forms\Components\DatePicker;
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

class QuotationCreationForm
{
    /**
     * @return array<int, Section>
     */
    public static function components(): array
    {
        return [
            Section::make('Quotation Details')
                ->schema([
                    Grid::make(2)->schema([
                        DatePicker::make('valid_until')
                            ->label('Valid Until')
                            ->native(false)
                            ->minDate(today())
                            ->default(today()->addWeek()),
                        TextInput::make('discount_amount')
                            ->label('Discount')
                            ->prefix('₱')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->default(0)
                            ->live(onBlur: true),
                    ]),
                ]),

            Section::make('Items')
                ->description('Add the frame, lens option, service, or other items discussed with the patient.')
                ->schema([
                    Repeater::make('items')
                        ->label('')
                        ->schema([
                            Select::make('item_type')
                                ->label('Item Type')
                                ->options([
                                    'catalog' => 'Catalog Item',
                                    'lens' => 'Lens Category',
                                    'custom' => 'Custom Item or Service',
                                ])
                                ->default('catalog')
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Set $set): void {
                                    $set('product_variant_id', null);
                                    $set('lens_category_id', null);
                                    $set('description', null);
                                    $set('unit_price', null);
                                }),
                            Select::make('product_variant_id')
                                ->label('Catalog Item')
                                ->options(fn (): array => ProductVariant::query()
                                    ->with('product')
                                    ->active()
                                    ->whereHas('product', fn (Builder $query): Builder => $query->where('is_active', true))
                                    ->orderBy('sku')
                                    ->get()
                                    ->mapWithKeys(fn (ProductVariant $variant): array => [
                                        $variant->id => "{$variant->product->name} — {$variant->name} ({$variant->sku})",
                                    ])
                                    ->all())
                                ->searchable()
                                ->preload()
                                ->required(fn (Get $get): bool => $get('item_type') === 'catalog')
                                ->visible(fn (Get $get): bool => $get('item_type') === 'catalog')
                                ->live()
                                ->afterStateUpdated(function (Set $set, mixed $state): void {
                                    $variant = ProductVariant::query()->with('product')->find($state);

                                    if ($variant === null) {
                                        return;
                                    }

                                    $set('description', "{$variant->product->name} — {$variant->name}");
                                    $set('unit_price', $variant->price);
                                }),
                            Select::make('lens_category_id')
                                ->label('Lens Category')
                                ->options(fn (): array => LensCategory::query()
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->preload()
                                ->required(fn (Get $get): bool => $get('item_type') === 'lens')
                                ->visible(fn (Get $get): bool => $get('item_type') === 'lens')
                                ->live()
                                ->afterStateUpdated(function (Set $set, mixed $state): void {
                                    $lensCategory = LensCategory::query()->find($state);

                                    if ($lensCategory === null) {
                                        return;
                                    }

                                    $set('description', $lensCategory->name);

                                    if ($lensCategory->price !== null) {
                                        $set('unit_price', $lensCategory->price);
                                    }
                                }),
                            TextInput::make('description')
                                ->label('Description')
                                ->required()
                                ->maxLength(255)
                                ->columnSpanFull(),
                            TextInput::make('quantity')
                                ->label('Quantity')
                                ->required()
                                ->integer()
                                ->minValue(1)
                                ->maxValue(999)
                                ->default(1)
                                ->live(onBlur: true),
                            TextInput::make('unit_price')
                                ->label('Unit Price')
                                ->prefix('₱')
                                ->required()
                                ->numeric()
                                ->minValue(0)
                                ->step(0.01)
                                ->live(onBlur: true),
                            Placeholder::make('line_total')
                                ->label('Line Total')
                                ->content(fn (Get $get): string => '₱'.number_format(
                                    ((float) ($get('quantity') ?? 0)) * ((float) ($get('unit_price') ?? 0)),
                                    2,
                                )),
                        ])
                        ->columns(3)
                        ->defaultItems(0)
                        ->minItems(1)
                        ->maxItems(50)
                        ->addActionLabel('Add Quotation Item'),
                ]),

            Section::make('Summary and Notes')
                ->schema([
                    Placeholder::make('estimated_total')
                        ->label('Estimated Total')
                        ->content(function (Get $get): string {
                            $subtotal = collect($get('items') ?? [])->sum(
                                fn (array $item): float => ((float) ($item['quantity'] ?? 0))
                                    * ((float) ($item['unit_price'] ?? 0)),
                            );
                            $discount = (float) ($get('discount_amount') ?? 0);

                            return '₱'.number_format(max($subtotal - $discount, 0), 2);
                        }),
                    Textarea::make('notes')
                        ->label('Patient Notes')
                        ->maxLength(2000)
                        ->columnSpanFull(),
                    Textarea::make('internal_notes')
                        ->label('Internal Notes')
                        ->helperText('Visible to clinic users only.')
                        ->maxLength(2000)
                        ->columnSpanFull(),
                ]),
        ];
    }
}
