<?php

namespace App\Filament\Resources\BillingRecords\Schemas;

use App\Models\Service;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ServiceChargeForm
{
    public static function items(): Repeater
    {
        return Repeater::make('items')
            ->hiddenLabel()
            ->itemLabel(fn (array $state): string => filled($state['description'] ?? null)
                ? $state['description']
                : 'New service line')
            ->schema([
                Radio::make('service_source')
                    ->label('Service source')
                    ->options([
                        'catalog' => 'Catalog service',
                        'custom' => 'Custom service',
                    ])
                    ->default('catalog')
                    ->inline()
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        $set('service_id', null);
                        $set('description', null);
                        $set('unit_price', null);
                        $set('line_total', null);

                        if ($state === 'custom') {
                            $set('quantity', 1);
                        }
                    })
                    ->columnSpanFull(),
                Select::make('service_id')
                    ->label('Service')
                    ->options(fn (): array => Service::query()
                        ->active()
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Service $service): array => [
                            $service->id => "{$service->name} (₱".number_format((float) $service->price, 2).')',
                        ])
                        ->all())
                    ->required(fn (Get $get): bool => $get('service_source') === 'catalog'
                        && (filled($get('service_id'))
                            || filled($get('description'))
                            || filled($get('unit_price'))))
                    ->visible(fn (Get $get): bool => $get('service_source') === 'catalog')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->columnSpanFull()
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
                TextInput::make('description')
                    ->required(fn (Get $get): bool => $get('service_source') === 'custom')
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('service_source') === 'custom')
                    ->dehydrated()
                    ->dehydratedWhenHidden()
                    ->columnSpanFull(),
                Grid::make(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('quantity')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->default(1)
                            ->required(fn (Get $get): bool => $get('service_source') === 'custom'
                                || filled($get('service_id')))
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                $set('line_total', number_format(
                                    ((float) ($get('quantity') ?? 0)) * ((float) ($get('unit_price') ?? 0)),
                                    2,
                                ));
                            }),
                        TextInput::make('unit_price')
                            ->label('Unit Price')
                            ->numeric()
                            ->prefix('₱')
                            ->minValue(0)
                            ->required(fn (Get $get): bool => $get('service_source') === 'custom'
                                || filled($get('service_id')))
                            ->helperText(fn (Get $get): ?string => $get('service_source') === 'catalog'
                                ? 'Catalog-controlled'
                                : null)
                            ->disabled(fn (Get $get): bool => $get('service_source') === 'catalog')
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
                    ]),
            ])
            ->columns(2)
            ->defaultItems(1)
            ->minItems(1)
            ->addActionLabel('Add Service Line');
    }

    public static function total(): Placeholder
    {
        return Placeholder::make('total')
            ->label('Total')
            ->content(function (Get $get): string {
                $total = collect($get('items') ?? [])->sum(
                    fn (array $item): float => ((float) ($item['quantity'] ?? 0))
                        * ((float) ($item['unit_price'] ?? 0)),
                );

                return '₱'.number_format($total, 2);
            });
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $items
     * @return Collection<int, array{description: string, quantity: int, unit_price: string, amount: string, service_id: int|null}>
     */
    public static function normalizeItems(array $items): Collection
    {
        $normalizedItems = collect($items)
            ->filter(fn (array $item): bool => collect([
                $item['service_id'] ?? null,
                $item['description'] ?? null,
                $item['unit_price'] ?? null,
            ])->contains(fn (mixed $value): bool => filled($value)))
            ->map(function (array $item): array {
                $service = null;
                $serviceSource = $item['service_source']
                    ?? (filled($item['service_id'] ?? null) ? 'catalog' : 'custom');

                if ($serviceSource === 'catalog') {
                    $service = filled($item['service_id'] ?? null)
                        ? Service::query()->active()->find((int) $item['service_id'])
                        : null;

                    if ($service === null) {
                        throw ValidationException::withMessages([
                            'items' => ['Select an active catalog service for each catalog line.'],
                        ]);
                    }
                }

                $unitPrice = $service?->price ?? $item['unit_price'];
                $unitPriceInCents = (int) round(((float) $unitPrice) * 100);
                $amountInCents = $unitPriceInCents * (int) $item['quantity'];

                return [
                    'description' => trim($service?->name ?? $item['description']),
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => number_format($unitPriceInCents / 100, 2, '.', ''),
                    'amount' => number_format($amountInCents / 100, 2, '.', ''),
                    'service_id' => $service?->id,
                ];
            })
            ->values();

        if ($normalizedItems->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => ['At least one service line is required.'],
            ]);
        }

        return $normalizedItems;
    }
}
