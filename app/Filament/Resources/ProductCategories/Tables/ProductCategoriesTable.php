<?php

namespace App\Filament\Resources\ProductCategories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProductCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('products_count')
                    ->counts('products')
                    ->label('Products')
                    ->sortable(),
            ])
            ->filters([
                TrashedFilter::make()
                    ->label('Show Archived')
                    ->placeholder('Active only')
                    ->trueLabel('Active and archived')
                    ->falseLabel('Archived only'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->modalIcon('heroicon-o-archive-box')
                    ->modalHeading('Archive category')
                    ->modalDescription('This will hide the category from active lists. It can be restored later from the "Show Archived" filter.')
                    ->modalSubmitActionLabel('Archive')
                    ->color('danger')
                    ->visible(fn ($record): bool => (auth()->user()?->isAdmin() ?? false) && ! $record->trashed()),
                RestoreAction::make()
                    ->label('Restore')
                    ->visible(fn ($record): bool => (auth()->user()?->isAdmin() ?? false) && $record->trashed()),
            ])
            ->defaultSort('name')
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Archive Selected')
                        ->icon('heroicon-o-archive-box')
                        ->modalIcon('heroicon-o-archive-box')
                        ->modalHeading('Archive selected categories')
                        ->modalDescription('This will hide the selected categories from active lists. They can be restored later from the "Show Archived" filter.')
                        ->modalSubmitActionLabel('Archive'),
                ]),
            ]);
    }
}
