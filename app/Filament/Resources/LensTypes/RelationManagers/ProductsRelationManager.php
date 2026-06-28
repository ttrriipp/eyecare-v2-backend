<?php

namespace App\Filament\Resources\LensTypes\RelationManagers;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                IconColumn::make('is_active')->label('Visible')->boolean(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('name')
            ->paginated(false);
    }
}
