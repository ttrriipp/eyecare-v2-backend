<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('images')
                    ->label('Image')
                    ->state(fn (Product $record): ?string => collect($record->images)->first())
                    ->square()
                    ->size(48),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('brand.name')
                    ->label('Brand')
                    ->searchable(),
                TextColumn::make('is_active')
                    ->label('Visibility')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Visible' : 'Hidden')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('stock')
                    ->label('Stock')
                    ->state(function (Product $record): string {
                        $hasLowStock = $record->variants
                            ->contains(fn ($v): bool => $v->stock_quantity <= $v->low_stock_threshold && $v->low_stock_threshold > 0);

                        return $hasLowStock ? 'Low stock' : 'OK';
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Low stock' ? 'danger' : 'success'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name');
    }
}
