<?php

namespace App\Filament\Resources\Prescriptions\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PrescriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('prescribed_at')
                    ->date()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->date()
                    ->sortable(),
                TextColumn::make('pd')
                    ->label('PD')
                    ->sortable(),
                TextColumn::make('createdBy.name')
                    ->label('Recorded by')
                    ->toggleable(),
            ])
            ->defaultSort('prescribed_at', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
