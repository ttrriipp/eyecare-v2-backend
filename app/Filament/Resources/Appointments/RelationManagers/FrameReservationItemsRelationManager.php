<?php

namespace App\Filament\Resources\Appointments\RelationManagers;

use App\Models\FrameReservationItem;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FrameReservationItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'frameReservationItems';

    protected static ?string $title = 'Reserved Frames';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('product_variant')
                ->label('Frame')
                ->content(fn (?FrameReservationItem $record): string => $record?->variant?->product
                    ? "{$record->variant->product->name} — {$record->variant->name} ({$record->variant->sku})"
                    : '—'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('variant.product.name')
                    ->label('Product')
                    ->sortable(),

                TextColumn::make('variant.name')
                    ->label('Variant')
                    ->sortable(),

                TextColumn::make('variant.sku')
                    ->label('SKU')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Reserved')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated(false)
            ->heading(null);
    }
}
