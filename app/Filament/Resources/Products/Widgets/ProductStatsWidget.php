<?php

namespace App\Filament\Resources\Products\Widgets;

use App\Models\Product;
use App\Models\ProductVariant;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProductStatsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $total = Product::query()->count();

        $lowStock = ProductVariant::query()
            ->active()
            ->where('low_stock_threshold', '>', 0)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->count();

        $outOfStock = ProductVariant::query()
            ->active()
            ->where('stock_quantity', '<=', 0)
            ->count();

        return [
            Stat::make('Total Products', number_format($total)),
            Stat::make('Low Stock Variants', number_format($lowStock))
                ->color($lowStock > 0 ? 'warning' : 'success'),
            Stat::make('Out of Stock', number_format($outOfStock))
                ->color($outOfStock > 0 ? 'danger' : 'success'),
        ];
    }
}
