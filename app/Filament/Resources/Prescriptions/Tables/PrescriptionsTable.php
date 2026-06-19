<?php

namespace App\Filament\Resources\Prescriptions\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
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
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                RestoreAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
            ]);
    }
}
