<?php

namespace App\Filament\Resources\OpticalOrders\Schemas;

use App\Models\LensCategory;
use App\Models\Patient;
use App\Models\ProductVariant;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class OpticalOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Patient')
                    ->schema([
                        Select::make('patient_id')
                            ->label('Patient')
                            ->options(Patient::query()
                                ->get()
                                ->mapWithKeys(fn ($p) => [$p->id => $p->full_name]))
                            ->required()
                            ->searchable()
                            ->preload(),
                    ])
                    ->columns(2),

                Section::make('Product Items')
                    ->schema([
                        Repeater::make('items')
                            ->schema([
                                Select::make('product_variant_id')
                                    ->label('Catalog Item')
                                    ->options(ProductVariant::query()
                                        ->active()
                                        ->whereHas('product', fn ($q) => $q->where('is_active', true))
                                        ->with('product')
                                        ->get()
                                        ->mapWithKeys(fn ($v) => [$v->id => "{$v->product->name} - {$v->name}"]))
                                    ->searchable()
                                    ->preload()
                                    ->nullable()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                                        if ($state !== null) {
                                            $variant = ProductVariant::find($state);
                                            if ($variant) {
                                                $set('description', "{$variant->product->name} - {$variant->name}");
                                                $set('unit_price', $variant->price);
                                            }
                                        }
                                    }),

                                Select::make('lens_category_id')
                                    ->label('Lens Category')
                                    ->options(LensCategory::query()->pluck('name', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->nullable()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                                        if ($state !== null) {
                                            $lens = LensCategory::find($state);
                                            if ($lens) {
                                                $set('description', $lens->name);
                                                $set('unit_price', $lens->price);
                                            }
                                        }
                                    }),

                                TextInput::make('description')
                                    ->required()
                                    ->maxLength(255),

                                Grid::make(3)
                                    ->schema([
                                        TextInput::make('quantity')
                                            ->required()
                                            ->numeric()
                                            ->integer()
                                            ->minValue(1)
                                            ->default(1)
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn (Set $set, Get $get) => $set(
                                                'amount',
                                                ((float) ($get('quantity') ?? 0)) * ((float) ($get('unit_price') ?? 0)),
                                            )),

                                        TextInput::make('unit_price')
                                            ->required()
                                            ->numeric()
                                            ->prefix('₱')
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn (Set $set, Get $get) => $set(
                                                'amount',
                                                ((float) ($get('quantity') ?? 0)) * ((float) ($get('unit_price') ?? 0)),
                                            )),

                                        TextInput::make('amount')
                                            ->numeric()
                                            ->prefix('₱')
                                            ->dehydrated()
                                            ->readOnly(),
                                    ]),
                            ])
                            ->columns(2)
                            ->addActionLabel('Add Product')
                            ->reorderable()
                            ->collapsible()
                            ->defaultItems(1)
                            ->columnSpanFull(),
                    ]),

                Placeholder::make('transaction_type')
                    ->label('Transaction Type')
                    ->content('Product-only'),

                Section::make('Pricing')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextInput::make('subtotal')
                                    ->numeric()
                                    ->prefix('₱')
                                    ->readOnly()
                                    ->dehydrated(),

                                TextInput::make('discount_amount')
                                    ->label('Discount')
                                    ->numeric()
                                    ->prefix('₱')
                                    ->default(0)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (Set $set, Get $get) => $set(
                                        'total',
                                        max(((float) ($get('subtotal') ?? 0)) - ((float) ($get('discount_amount') ?? 0)), 0),
                                    )),

                                TextInput::make('total')
                                    ->numeric()
                                    ->prefix('₱')
                                    ->readOnly()
                                    ->dehydrated(),
                            ]),
                    ]),

                Section::make('Notes')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
