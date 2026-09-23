<?php

namespace App\Filament\Resources\AppointmentRequests\Widgets;

use App\Models\AppointmentRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class AppointmentRequestStatsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $totalCount = AppointmentRequest::query()->count();
        $needsLinkCount = AppointmentRequest::query()->needsLink()->count();
        $needsReviewCount = AppointmentRequest::query()->needsReview()->count();
        $resolvedCount = AppointmentRequest::query()->resolvedForTriage()->count();

        return [
            Stat::make('Total Requests', Number::format($totalCount))
                ->color('gray'),
            Stat::make('Needs Link', Number::format($needsLinkCount))
                ->color($needsLinkCount > 0 ? 'warning' : 'gray'),
            Stat::make('Needs Review', Number::format($needsReviewCount))
                ->color($needsReviewCount > 0 ? 'warning' : 'gray'),
            Stat::make('Resolved', Number::format($resolvedCount))
                ->color($resolvedCount > 0 ? 'success' : 'gray'),
        ];
    }
}
