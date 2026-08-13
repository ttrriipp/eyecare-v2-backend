<?php

namespace App\Filament\Resources\Inventory\Tables;

use App\Filament\Support\StockActions;
use App\Models\ProductVariant;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InventoryTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable()
                    ->description(fn (ProductVariant $record): ?string => $record->product?->brand?->name),

                TextColumn::make('name')
                    ->label('Variant')
                    ->searchable(),

                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('stock_quantity')
                    ->label('In Stock')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (ProductVariant $record): string => match (true) {
                        $record->stock_quantity <= 0 => 'danger',
                        $record->isLowStock() => 'warning',
                        default => 'success',
                    }),

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

                TextColumn::make('suggested_reorder')
                    ->label('Suggested Order')
                    ->state(fn (ProductVariant $record): ?int => $record->suggestedReorderQuantity())
                    ->placeholder('—')
                    ->color(fn (?int $state): ?string => $state > 0 ? 'warning' : null),
            ])
            // Emptiest first: this queue exists to surface what is about to run out.
            ->defaultSort('stock_quantity', 'asc')
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
            ->recordActions([
                ActionGroup::make([
                    StockActions::receive(),
                    StockActions::writeOffDamaged(),
                ]),
            ])
            ->emptyStateHeading('No stock items')
            ->emptyStateDescription('Product variants and their stock levels appear here once products have been added.');
    }
}
