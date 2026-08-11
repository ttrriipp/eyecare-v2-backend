<?php

namespace App\Filament\Widgets;

use App\Enums\BillingRecordStatus;
use App\Enums\QuotationStatus;
use App\Filament\Pages\Reports\ReorderReport;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
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

    protected ?string $heading = 'Other issues';

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
        return 'Lower-priority follow-up items. Updated at '.now()->format('g:i A').'.';
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
                'Quotations Awaiting Decision',
                Number::format(Quotation::query()->where('status', QuotationStatus::Presented)->count()),
            )
                ->description('Presented to patients')
                ->descriptionIcon(Heroicon::OutlinedDocumentCurrencyDollar)
                ->color('gray')
                ->url(QuotationResource::getUrl('index', [
                    'tableFilters' => [
                        'status' => ['value' => QuotationStatus::Presented->value],
                    ],
                ])),
            Stat::make(
                'Balances Due',
                Number::format(BillingRecord::query()
                    ->whereIn('status', [BillingRecordStatus::Unpaid, BillingRecordStatus::PartiallyPaid])
                    ->count()),
            )
                ->description('Unpaid or partially paid')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('warning')
                ->url(BillingRecordResource::getUrl('index', [
                    'activeTab' => 'outstanding',
                ])),
            Stat::make(
                'Low Stock Items',
                Number::format(ProductVariant::query()->active()->needsReorder()->count()),
            )
                ->description('At or below reorder threshold')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')
                ->url(ReorderReport::getUrl()),
        ];
    }
}
