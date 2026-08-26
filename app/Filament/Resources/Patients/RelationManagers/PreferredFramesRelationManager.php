<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PreferredFramesRelationManager extends RelationManager
{
    protected static string $relationship = 'savedFrames';

    protected static ?string $title = 'Preferred Frames';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCatalogData())
            ->columns([
                ImageColumn::make('variant.product.images')
                    ->label('')
                    ->size(40)
                    ->getStateUsing(function ($record): ?string {
                        $images = $record->variant?->images;

                        return is_array($images) ? ($images[0] ?? null) : null;
                    })
                    ->defaultImageUrl(url('/images/placeholder-frame.svg')),
                TextColumn::make('variant.product.name')
                    ->label('Frame')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('variant.name')
                    ->label('Variant')
                    ->searchable(),
                TextColumn::make('variant.sku')
                    ->label('SKU')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label('Saved')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                TextColumn::make('availability')
                    ->label('Availability')
                    ->getStateUsing(function ($record): string {
                        $variant = $record->variant;

                        if ($variant === null || $variant->trashed() || ! $variant->is_active) {
                            return 'Inactive';
                        }

                        $product = $variant->product;

                        if ($product === null || $product->trashed() || ! $product->is_active) {
                            return 'Inactive';
                        }

                        if ($variant->stock_quantity <= 0) {
                            return 'Out of stock';
                        }

                        if ($variant->isLowStock()) {
                            return 'Low stock';
                        }

                        return 'Available';
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Available' => 'success',
                        'Low stock' => 'warning',
                        'Out of stock' => 'danger',
                        'Inactive' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('variant.stock_quantity')
                    ->label('Qty')
                    ->numeric()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(fn (): string => $this->getOwnerRecord()->user_id === null
                ? 'No linked account'
                : 'No preferred frames')
            ->headerActions([])
            ->toolbarActions([])
            ->recordActions([])
            ->paginated([10, 25, 50]);
    }
}
