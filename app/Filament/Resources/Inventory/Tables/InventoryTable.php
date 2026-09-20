<?php

namespace App\Filament\Resources\Inventory\Tables;

use App\Filament\Support\StockActions;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->modifyQueryUsing(function (Builder $query): Builder {
                $variantKey = $query->getModel()->qualifyColumn($query->getModel()->getKeyName());

                return $query
                    ->addSelect([
                        'latest_purchase_date' => InventoryMovement::query()
                            ->select('purchased_at')
                            ->whereColumn('product_variant_id', $variantKey)
                            ->where('quantity_change', '>', 0)
                            ->whereHas(
                                'movementType',
                                fn (Builder $movementTypeQuery): Builder => $movementTypeQuery->where('name', 'restock'),
                            )
                            ->whereNotNull('purchased_at')
                            ->orderBy('purchased_at')
                            ->orderBy('id')
                            ->limit(1),
                        'early_purchase_quantity' => InventoryMovement::query()
                            ->selectRaw('SUM(quantity_change)')
                            ->whereColumn('product_variant_id', $variantKey)
                            ->where('quantity_change', '>', 0)
                            ->whereHas(
                                'movementType',
                                fn (Builder $movementTypeQuery): Builder => $movementTypeQuery->where('name', 'restock'),
                            )
                            ->whereNotNull('purchased_at')
                            ->where(
                                'purchased_at',
                                '=',
                                InventoryMovement::query()
                                    ->select('purchased_at')
                                    ->whereColumn('product_variant_id', $variantKey)
                                    ->where('quantity_change', '>', 0)
                                    ->whereHas(
                                        'movementType',
                                        fn (Builder $movementTypeQuery): Builder => $movementTypeQuery->where('name', 'restock'),
                                    )
                                    ->whereNotNull('purchased_at')
                                    ->orderBy('purchased_at')
                                    ->orderBy('id')
                                    ->limit(1),
                            ),
                    ])
                    ->withCasts([
                        'latest_purchase_date' => 'date',
                        'early_purchase_quantity' => 'integer',
                    ]);
            })
            ->columns([
                TextColumn::make('product.name')
                    ->weight('bold')
                    ->label('Product')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(fn (ProductVariant $record): ?string => $record->product?->brand?->name),

                TextColumn::make('name')
                    ->weight('bold')
                    ->label('Variant')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('latest_purchase_date')
                    ->label('Received At')
                    ->date('M j, Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('early_purchase_quantity')
                    ->label('EarlyQty')
                    ->numeric()
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('stock_quantity')
                    ->label('On Hand')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (ProductVariant $record): string => match (true) {
                        $record->stock_quantity <= 0 => 'danger',
                        $record->isLowStock() => 'warning',
                        default => 'success',
                    }),

                TextColumn::make('usable_stock')
                    ->label('Usable')
                    ->state(fn (ProductVariant $record): ?int => $record->usableStockQuantity())
                    ->numeric()
                    ->badge()
                    ->color(fn (ProductVariant $record): string => match ($record->expiryStatus()) {
                        'out_of_stock', 'expired' => 'danger',
                        'expiring_soon' => 'warning',
                        default => 'success',
                    })
                    ->visible(fn (?ProductVariant $record): bool => $record?->isExpiryTracked() ?? false),

                TextColumn::make('earliest_expiry')
                    ->label('Earliest Expiry')
                    ->state(fn (ProductVariant $record): ?string => $record->earliestUsableExpiry()?->toDateString())
                    ->placeholder('—')
                    ->visible(fn (?ProductVariant $record): bool => $record?->isExpiryTracked() ?? false),

                TextColumn::make('expiry_warning_date')
                    ->label('Expiring Soon Date')
                    ->state(fn (ProductVariant $record): ?string => $record->earliestExpiryWarningDate()?->toDateString())
                    ->date('M j, Y')
                    ->placeholder('—')
                    ->visible(fn (?ProductVariant $record): bool => $record?->isExpiryTracked() ?? false),

                TextColumn::make('expiry_status')
                    ->label('Expiry Status')
                    ->state(fn (ProductVariant $record): ?string => $record->expiryStatusLabel())
                    ->badge()
                    ->color(fn (ProductVariant $record): string => match ($record->expiryStatus()) {
                        'out_of_stock', 'expired' => 'danger',
                        'expiring_soon' => 'warning',
                        default => 'success',
                    })
                    ->visible(fn (?ProductVariant $record): bool => $record?->isExpiryTracked() ?? false),

                TextColumn::make('low_stock_threshold')
                    ->label('Threshold')
                    ->numeric()
                    ->placeholder('Not set')
                    ->toggleable(),

                TextColumn::make('target_stock_level')
                    ->label('Target')
                    ->numeric()
                    ->placeholder('Not set')
                    ->toggleable(),
            ])
            // Sort by the latest recorded receipt, leaving variants with no restock history last.
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc(
                InventoryMovement::query()
                    ->select('created_at')
                    ->whereColumn(
                        'product_variant_id',
                        $query->getModel()->qualifyColumn($query->getModel()->getKeyName()),
                    )
                    ->where('quantity_change', '>', 0)
                    ->whereHas(
                        'movementType',
                        fn (Builder $movementTypeQuery): Builder => $movementTypeQuery->where('name', 'restock'),
                    )
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(1),
            ))
            ->filters([
                SelectFilter::make('product')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Product'),

                SelectFilter::make('is_active')
                    ->label('Status')
                    ->options([
                        '1' => 'Active',
                        '0' => 'Inactive',
                    ]),
            ])
            ->recordAction('view')
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->modalHeading('Inventory details'),
                    StockActions::viewBatches(),
                    StockActions::receive(),
                    StockActions::writeOffDamaged(),
                ]),
            ])
            ->emptyStateHeading('No stock items')
            ->emptyStateDescription('Product variants and their stock levels appear here once products have been added.');
    }
}
