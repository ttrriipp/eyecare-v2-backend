<?php

namespace App\Filament\Resources\InventoryMovements\Tables;

use App\Models\InventoryMovement;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                TextColumn::make('variant.product.name')
                    ->label('Product')
                    ->searchable(),
                TextColumn::make('variant.name')
                    ->label('Variant')
                    ->searchable(),
                TextColumn::make('movementType.name')
                    ->label('Type')
                    ->badge()
                    ->color(fn (InventoryMovement $record): string => match ($record->movementType?->name) {
                        'restock' => 'success',
                        'order_commitment' => 'warning',
                        'order_reversal' => 'info',
                        'manual_adjustment' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state))),
                TextColumn::make('quantity_change')
                    ->label('Change')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? "+{$state}" : (string) $state)
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger'),
                TextColumn::make('previous_stock')
                    ->label('Before')
                    ->placeholder('—'),
                TextColumn::make('new_stock')
                    ->label('After')
                    ->placeholder('—'),
                TextColumn::make('createdBy.name')
                    ->label('By')
                    ->placeholder('System')
                    ->toggleable(),
                TextColumn::make('order.order_number')
                    ->label('Order')
                    ->placeholder('—')
                    ->url(fn (InventoryMovement $record): ?string => $record->order_id
                        ? route('filament.admin.resources.orders.edit', $record->order_id)
                        : null
                    )
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('movementType')
                    ->relationship('movementType', 'name')
                    ->label('Type'),
                Filter::make('date_range')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
                    })
                    ->columns(2),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordAction(null);
    }
}
