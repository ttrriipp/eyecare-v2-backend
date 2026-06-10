<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('brand.name')
                    ->label('Brand'),
                TextColumn::make('category.name')
                    ->label('Category'),
                TextColumn::make('price')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('low_stock_state')
                    ->label('Stock')
                    ->state(function (Product $record): string {
                        $hasLowStock = $record->variants
                            ->contains(fn ($variant): bool => $variant->stock_quantity <= $variant->low_stock_threshold);

                        return $hasLowStock ? 'Low stock' : 'OK';
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Low stock' ? 'danger' : 'success'),
                TextColumn::make('is_active')
                    ->label('Active')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name');
    }
}
