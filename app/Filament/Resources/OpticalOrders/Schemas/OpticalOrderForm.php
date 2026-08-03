<?php

namespace App\Filament\Resources\OpticalOrders\Schemas;

use App\Models\Encounter;
use App\Models\LensCategory;
use App\Models\Patient;
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
use Filament\Schemas\Schema;

class OpticalOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Patient & Prescription')
                    ->schema([
                        Select::make('patient_id')
                            ->label('Patient')
                            ->options(Patient::query()
                                ->get()
                                ->mapWithKeys(fn ($p) => [$p->id => $p->full_name]))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('encounter_id', null))
                            ->default(fn () => request()->query('encounter') ? Encounter::find(request()->query('encounter'))?->patient_id : null),

                        Select::make('encounter_id')
                            ->label('Encounter')
                            ->options(fn (Get $get) => blank($get('patient_id'))
                                ? collect()
                                : Patient::find($get('patient_id'))
                                    ?->encounters()
                                    ->whereIn('status', ['in_progress', 'completed'])
                                    ->pluck('encounter_number', 'id')
                                    ?? collect())
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->default(fn () => request()->query('encounter')),

                        DatePicker::make('valid_until')
                            ->label('Valid Until')
                            ->native(false)
                            ->minDate(today())
                            ->nullable(),
                    ])
                    ->columns(3),

                Section::make('Items')
                    ->schema([
                        Repeater::make('items')
                            ->schema([
                                Select::make('item_mode')
                                    ->label('Type')
                                    ->options([
                                        'product' => 'Product',
                                        'service' => 'Service',
                                    ])
                                    ->default('product')
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                        if ($state === 'service') {
                                            $set('product_variant_id', null);
                                            $set('lens_category_id', null);
                                        }
                                    }),

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
                                    ->visible(fn (Get $get): bool => $get('item_mode') === 'product')
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
                                    ->visible(fn (Get $get): bool => $get('item_mode') === 'product')
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
                            ->addActionLabel('Add Item')
                            ->reorderable()
                            ->collapsible()
                            ->defaultItems(1)
                            ->columnSpanFull(),
                    ]),

                Placeholder::make('transaction_type')
                    ->label('Transaction Type')
                    ->content(function ($record, Get $get): string {
                        $items = $get('items') ?? [];
                        $hasProducts = false;
                        $hasServices = false;

                        foreach ($items as $item) {
                            if (($item['item_mode'] ?? 'product') === 'product') {
                                $hasProducts = true;
                            } else {
                                $hasServices = true;
                            }
                        }

                        if ($hasProducts && $hasServices) {
                            return 'Mixed';
                        }

                        if ($hasProducts) {
                            return 'Product-only';
                        }

                        return 'Service-only';
                    }),

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
                            ->label('Patient-visible notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                // Summary placeholders for edit mode
                Placeholder::make('status_display')
                    ->label('Status')
                    ->content(fn ($record) => $record?->status?->value ?? 'draft')
                    ->visible(fn ($record) => $record !== null),

                Placeholder::make('fulfillment_display')
                    ->label('Fulfillment')
                    ->content(fn ($record) => match ($record?->jobOrder?->fulfillment_mode) {
                        'immediate' => 'Completed',
                        'prepared' => $record?->jobOrder?->status?->value === 'queued' ? 'Confirmed' : ucfirst($record?->jobOrder?->status?->value ?? 'No order yet'),
                        default => 'No order yet',
                    })
                    ->visible(fn ($record) => $record !== null),

                Placeholder::make('payment_display')
                    ->label('Payment')
                    ->content(fn ($record) => $record?->jobOrder?->billingRecord?->status?->getLabel() ?? '—')
                    ->visible(fn ($record) => $record !== null),
            ]);
    }
}
