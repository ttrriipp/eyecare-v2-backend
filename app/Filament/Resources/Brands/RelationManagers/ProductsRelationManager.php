<?php

namespace App\Filament\Resources\Brands\RelationManagers;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\CatalogLifecycleActions;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScope(SoftDeletingScope::class))
            ->columns([
                TextColumn::make('name')->weight('bold')->searchable()->sortable(),
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
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([
                CatalogLifecycleActions::statusFilter(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record])),
                ...CatalogLifecycleActions::recordActions('product'),
            ])
            ->defaultSort('name')
            ->toolbarActions([
                CatalogLifecycleActions::bulkActions(),
            ])
            ->paginated(false);
    }
}
