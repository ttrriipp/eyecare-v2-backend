<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                TextColumn::make('product_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'frame' => 'Frame',
                        'lens' => 'Lens',
                        'contact_lens' => 'Contact Lens',
                        'accessory' => 'Accessory',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'frame' => 'info',
                        'lens' => 'success',
                        'contact_lens' => 'warning',
                        'accessory' => 'gray',
                        default => 'gray',
                    }),
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
            ->filters([
                SelectFilter::make('product_type')
                    ->label('Type')
                    ->options([
                        'frame' => 'Frame',
                        'lens' => 'Lens',
                        'contact_lens' => 'Contact Lens',
                        'accessory' => 'Accessory',
                    ]),
                SelectFilter::make('is_active')
                    ->label('Visibility')
                    ->options([
                        '1' => 'Visible',
                        '0' => 'Hidden',
                    ]),
            ])
            ->defaultSort('name');
    }
}
