<?php

namespace App\Filament\Resources\Feedback\Widgets;

use App\Models\Feedback;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FeedbackStatsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $total = Feedback::query()->count();
        $avg = $total > 0 ? (float) Feedback::query()->avg('rating') : 0;

        $fiveStarCount = Feedback::query()->where('rating', 5)->count();
        $fiveStarPct = $total > 0 ? round(($fiveStarCount / $total) * 100) : 0;

        $lastThirtyDays = Feedback::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $stars = match (true) {
            $avg >= 4.5 => '⭐⭐⭐⭐⭐',
            $avg >= 3.5 => '⭐⭐⭐⭐',
            $avg >= 2.5 => '⭐⭐⭐',
            $avg >= 1.5 => '⭐⭐',
            default => '⭐',
        };

        return [
            Stat::make('Total Reviews', number_format($total))
                ->icon('heroicon-o-chat-bubble-left-right'),

            Stat::make('Average Rating', $total > 0 ? number_format($avg, 1).' '.$stars : '—')
                ->icon('heroicon-o-star')
                ->color($avg >= 4 ? 'success' : ($avg >= 3 ? 'warning' : 'danger')),

            Stat::make('5-Star Reviews', "{$fiveStarPct}% ({$fiveStarCount})")
                ->icon('heroicon-o-star')
                ->color('success'),

            Stat::make('Last 30 Days', number_format($lastThirtyDays))
                ->icon('heroicon-o-calendar')
                ->color('info'),
        ];
    }
}
