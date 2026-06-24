<?php

namespace App\Filament\Resources\Services\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServicesTable
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
                TextColumn::make('description')
                    ->limit(50)
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Visible')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name');
    }
}
