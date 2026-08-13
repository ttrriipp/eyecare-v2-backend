<?php

namespace App\Filament\Resources\Inventory\Pages;

use App\Filament\Resources\Inventory\InventoryResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInventory extends ListRecords
{
    protected static string $resource = InventoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * "All" stays first and default so the landing view never hides stock
     * behind a tab. "Needs Reorder" is the actionable one and carries the
     * same count as the navigation badge.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),

            'needs_reorder' => Tab::make('Needs Reorder')
                ->modifyQueryUsing(fn (Builder $query) => $query->active()->needsReorder()),

            'out_of_stock' => Tab::make('Out of Stock')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('stock_quantity', '<=', 0)),
        ];
    }
}
