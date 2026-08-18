<?php

namespace App\Filament\Resources\Inventory\Widgets;

use App\Models\ProductVariant;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class InventoryStatsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $totalVariants = ProductVariant::query()->active()->count();

        $lowStock = ProductVariant::query()
            ->active()
            ->needsReorder()
            ->count();

        $outOfStock = ProductVariant::query()
            ->active()
            ->where('stock_quantity', '<=', 0)
            ->count();

        $totalValue = ProductVariant::query()
            ->active()
            ->sum(DB::raw('stock_quantity * price'));

        return [
            Stat::make('Active Variants', number_format($totalVariants)),

            Stat::make('Low Stock', number_format($lowStock))
                ->color($lowStock > 0 ? 'warning' : 'success')
                ->description('At or below reorder level'),

            Stat::make('Out of Stock', number_format($outOfStock))
                ->color($outOfStock > 0 ? 'danger' : 'success'),

            Stat::make('Stock Value', '₱'.number_format($totalValue, 0)),
        ];
    }
}
