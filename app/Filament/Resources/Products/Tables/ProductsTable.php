<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Support\CatalogLifecycleActions;
use App\Models\Product;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
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
                    ->disk('public')
                    ->square()
                    ->size(48),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('brand.name')
                    ->label('Brand')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('product_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Product::TYPE_OPTIONS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'frame' => 'info',
                        'contact_lens' => 'warning',
                        'accessory' => 'gray',
                        default => 'gray',
                    }),
                IconColumn::make('is_active')
                    ->label('Visible')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                TextColumn::make('total_quantity')
                    ->label('Qty')
                    ->state(fn (Product $record): int => $record->variants->sum('stock_quantity'))
                    ->sortable(false)
                    ->toggleable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    ...CatalogLifecycleActions::recordActions('product'),
                ]),
            ])
            ->filters([
                SelectFilter::make('product_type')
                    ->label('Type')
                    ->options(Product::TYPE_OPTIONS),
                CatalogLifecycleActions::statusFilter(),
            ])
            ->defaultSort('name')
            ->toolbarActions([
                CatalogLifecycleActions::bulkActions(),
            ]);
    }
}
