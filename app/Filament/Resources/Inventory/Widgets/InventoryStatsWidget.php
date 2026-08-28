<?php

namespace App\Filament\Resources\Inventory\Widgets;

use App\Models\ProductVariant;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
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

        $expiringSoon = ProductVariant::query()
            ->active()
            ->contactLenses()
            ->whereHas('inventoryLots', fn (Builder $query): Builder => $query->expiringSoon())
            ->count();

        $expired = ProductVariant::query()
            ->active()
            ->contactLenses()
            ->whereHas('inventoryLots', fn (Builder $query): Builder => $query->expired()->available())
            ->whereDoesntHave(
                'inventoryLots',
                fn (Builder $query): Builder => $query->notExpired()->available(),
            )
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

            Stat::make('Expiring Soon', number_format($expiringSoon))
                ->color($expiringSoon > 0 ? 'warning' : 'success')
                ->description('Usable lots within the warning window'),

            Stat::make('Expired', number_format($expired))
                ->color($expired > 0 ? 'danger' : 'success')
                ->description('No usable lot remains'),

            Stat::make('Stock Value', '₱'.number_format($totalValue, 0)),
        ];
    }
}
