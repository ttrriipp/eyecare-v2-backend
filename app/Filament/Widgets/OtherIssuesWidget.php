<?php

namespace App\Filament\Widgets;

use App\Enums\BillingRecordStatus;
use App\Enums\QuotationStatus;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Filament\Resources\Inventory\InventoryResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\BillingRecord;
use App\Models\ProductVariant;
use App\Models\Quotation;
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
                'Draft Quotations',
                Number::format(Quotation::query()->where('status', QuotationStatus::Draft)->count()),
            )
                ->description('Pending decision')
                ->descriptionIcon(Heroicon::OutlinedDocumentCurrencyDollar)
                ->color('gray')
                ->url(QuotationResource::getUrl('index', [
                    'tableFilters' => [
                        'status' => ['value' => QuotationStatus::Draft->value],
                    ],
                ])),
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
                Number::format(ProductVariant::query()->active()->needsReorder()->count()),
            )
                ->description('Below reorder level')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')
                ->url(InventoryResource::getUrl('index', [
                    'activeTab' => 'needs_reorder',
                ])),
        ];
    }
}
