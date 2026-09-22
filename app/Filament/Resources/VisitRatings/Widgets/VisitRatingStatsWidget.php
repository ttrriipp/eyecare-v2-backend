<?php

namespace App\Filament\Resources\VisitRatings\Widgets;

use App\Models\VisitRating;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class VisitRatingStatsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $total = VisitRating::query()->count();
        $average = (float) (VisitRating::query()->avg('rating') ?? 0);
        $lowRatings = VisitRating::query()->where('rating', '<=', 2)->count();

        return [
            Stat::make('Total ratings', Number::format($total)),
            Stat::make('Average rating', Number::format($average, 1).' / 5')
                ->color(match (true) {
                    $average >= 4 => 'success',
                    $average >= 3 => 'warning',
                    $total > 0 => 'danger',
                    default => 'gray',
                }),
            Stat::make('Low ratings', Number::format($lowRatings))
                ->color($lowRatings > 0 ? 'danger' : 'gray'),
        ];
    }
}
