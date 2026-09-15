<?php

namespace App\Filament\Widgets;

use App\Enums\BillingRecordStatus;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Filament\Resources\Inventory\InventoryResource;
use App\Models\BillingRecord;
use App\Models\ProductVariant;
use App\Models\Role;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class OtherIssuesWidget extends BaseStatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Also noted';

    protected ?string $pollingInterval = '30s';

    protected int|array|null $columns = 3;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->loadMissing('roles')->roles->pluck('name')->contains(Role::Admin);
    }

    protected function getDescription(): ?string
    {
        return null;
    }

    public function getSectionContentComponent(): Component
    {
        return parent::getSectionContentComponent()
            ->collapsible()
            ->collapsed();
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        return [
            Stat::make(
                'Balances Due',
                Number::format(BillingRecord::query()
                    ->whereIn('status', [BillingRecordStatus::Unpaid, BillingRecordStatus::PartiallyPaid])
                    ->count()),
            )
                ->description('Unpaid invoices')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('warning')
                ->url(BillingRecordResource::getUrl('index', [
                    'activeTab' => 'outstanding',
                ])),
            Stat::make(
                'Low Stock',
                Number::format(ProductVariant::query()->active()->where('low_stock_threshold', '>', 0)->whereColumn('stock_quantity', '<=', 'low_stock_threshold')->count()),
            )
                ->description('Below threshold')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')
                ->url(InventoryResource::getUrl('index')),
        ];
    }
}
