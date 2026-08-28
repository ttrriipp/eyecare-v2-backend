<?php

namespace App\Filament\Resources\Inventory;

use App\Filament\Resources\Inventory\Pages\ListInventory;
use App\Filament\Resources\Inventory\Tables\InventoryTable;
use App\Models\ProductVariant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Stock levels across every product, listed per variant.
 *
 * Stock is variant-level, so the Products resource cannot answer the
 * questions this one exists for — its quantity column sums a product's
 * variants, which hides the single colourway sitting at zero.
 *
 * Deliberately read-only apart from the stock movements themselves. Editing a
 * variant's name, price, images, or attributes stays in Products, so there is
 * only ever one editor for a given record.
 */
class InventoryResource extends Resource
{
    protected static ?string $model = ProductVariant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $navigationLabel = 'Inventory';

    protected static ?int $navigationSort = 15;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $modelLabel = 'Stock Item';

    protected static ?string $pluralModelLabel = 'Inventory';

    protected static ?string $recordTitleAttribute = 'sku';

    protected static ?string $slug = 'inventory';

    public static function getNavigationBadge(): ?string
    {
        $count = ProductVariant::query()
            ->active()
            ->needsReorder()
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'product:id,name,brand_id,product_type',
            'product.brand:id,name',
            'inventoryLots:id,product_variant_id,lot_number,expires_on,quantity_on_hand,received_at,received_by,source_reference',
            'inventoryLots.receivedBy:id,first_name,middle_name,last_name',
        ]);
    }

    public static function canCreate(): bool
    {
        return false; // Variants are created against their product in the Products resource.
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return InventoryTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventory::route('/'),
        ];
    }
}
