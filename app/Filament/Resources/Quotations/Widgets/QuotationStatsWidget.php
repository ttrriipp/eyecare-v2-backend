<?php

namespace App\Filament\Resources\Quotations\Widgets;

use App\Enums\QuotationStatus;
use App\Models\Quotation;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class QuotationStatsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $draftCount = Quotation::query()
            ->where('status', QuotationStatus::Draft)
            ->count();

        $acceptedCount = Quotation::query()
            ->where('status', QuotationStatus::Accepted)
            ->count();

        $declinedCount = Quotation::query()
            ->where('status', QuotationStatus::Declined)
            ->count();

        $draftValue = Quotation::query()
            ->where('status', QuotationStatus::Draft)
            ->sum('total');

        return [
            Stat::make('Draft', Number::format($draftCount))
                ->color($draftCount > 0 ? 'warning' : 'gray'),

            Stat::make('Accepted', Number::format($acceptedCount))
                ->color($acceptedCount > 0 ? 'success' : 'gray'),

            Stat::make('Declined', Number::format($declinedCount))
                ->color($declinedCount > 0 ? 'danger' : 'gray'),

            Stat::make('Draft Value', '₱'.number_format((float) $draftValue, 2))
                ->color($draftValue > 0 ? 'primary' : 'gray'),
        ];
    }
}
