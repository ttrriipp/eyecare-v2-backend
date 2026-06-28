<?php

namespace App\Filament\Resources\ProductCategories\RelationManagers;

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
