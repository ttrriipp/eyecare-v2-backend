<?php

namespace App\Filament\Resources\Brands\Tables;

use App\Filament\Support\CatalogLifecycleActions;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('products_count')
                    ->counts('products')
                    ->label('Products')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
            ])
            ->filters([
                CatalogLifecycleActions::statusFilter(),
            ])
            ->recordActions([
                EditAction::make(),
                ...CatalogLifecycleActions::recordActions('brand'),
            ])
            ->toolbarActions([
                CatalogLifecycleActions::bulkActions(),
            ]);
    }
}
