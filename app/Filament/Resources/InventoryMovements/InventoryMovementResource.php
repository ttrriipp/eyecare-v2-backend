<?php

namespace App\Filament\Resources\InventoryMovements;

use App\Filament\Resources\InventoryMovements\Pages\ListInventoryMovements;
use App\Filament\Resources\InventoryMovements\Tables\InventoryMovementsTable;
use App\Models\InventoryMovement;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class InventoryMovementResource extends Resource
{
    protected static ?string $model = InventoryMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    protected static ?string $navigationLabel = 'Inventory History';

    protected static ?int $navigationSort = 21;

    protected static string|UnitEnum|null $navigationGroup = 'Products & Inventory';

    protected static ?string $modelLabel = 'Inventory History';

    protected static ?string $pluralModelLabel = 'Inventory History';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Movement Details')->columns(3)->schema([
                TextEntry::make('created_at')->label('Date')->dateTime('M j, Y g:i A'),
                TextEntry::make('variant.product.name')->label('Product'),
                TextEntry::make('variant.name')->label('Variant'),
                TextEntry::make('movementType.name')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'restock' => 'success',
                        'order_commitment' => 'warning',
                        'order_reversal' => 'info',
                        'damaged' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state))),
                TextEntry::make('quantity_change')
                    ->label('Change')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? "+{$state}" : (string) $state)
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger'),
                TextEntry::make('previous_stock')->label('Before')->placeholder('—'),
                TextEntry::make('new_stock')->label('After')->placeholder('—'),
                TextEntry::make('createdBy.name')->label('Recorded By')->placeholder('System'),
                TextEntry::make('order.order_number')->label('Order')->placeholder('—'),
                TextEntry::make('notes')->label('Notes')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return InventoryMovementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryMovements::route('/'),
        ];
    }
}
