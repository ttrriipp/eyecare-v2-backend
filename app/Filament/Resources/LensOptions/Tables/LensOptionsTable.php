<?php

namespace App\Filament\Resources\LensOptions\Tables;

use App\Filament\Support\CatalogLifecycleActions;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LensOptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('price')
                    ->money('PHP')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                TextColumn::make('description')
                    ->limit(60)
                    ->toggleable(),
            ])
            ->filters([
                CatalogLifecycleActions::statusFilter(),
            ])
            ->recordActions([
                EditAction::make(),
                ...CatalogLifecycleActions::recordActions('lens option'),
            ])
            ->defaultSort('name')
            ->toolbarActions([
                CatalogLifecycleActions::bulkActions(),
            ]);
    }
}
