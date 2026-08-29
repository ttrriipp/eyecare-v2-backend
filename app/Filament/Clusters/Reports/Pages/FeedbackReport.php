<?php

namespace App\Filament\Clusters\Reports\Pages;

use App\Models\FrameRating;
use App\Models\VisitRating;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FeedbackReport extends ReportsClusterPage
{
    protected static ?string $title = 'Feedback';

    protected static ?string $navigationLabel = 'Feedback';

    protected static ?int $navigationSort = 4;

    /**
     * @return array{stats: array<int, Stat>, sections: array<int, array<string, mixed>>}
     */
    protected function buildReport(): array
    {
        $visitRatings = $this->constrainToPeriod(
            VisitRating::query(),
            'visit_ratings.created_at',
        );
        $frameRatings = $this->constrainToPeriod(
            FrameRating::query(),
            'frame_ratings.created_at',
        );

        $visitCount = (clone $visitRatings)->count();
        $frameCount = (clone $frameRatings)->count();
        $visitAverage = (float) ((clone $visitRatings)->avg('rating') ?? 0);
        $frameAverage = (float) ((clone $frameRatings)->avg('rating') ?? 0);

        $visitDistribution = (clone $visitRatings)
            ->select('rating', DB::raw('COUNT(*) AS rating_count'))
            ->groupBy('rating')
            ->pluck('rating_count', 'rating')
            ->map(fn (int|string $count): int => (int) $count);
        $frameDistribution = (clone $frameRatings)
            ->select('rating', DB::raw('COUNT(*) AS rating_count'))
            ->groupBy('rating')
            ->pluck('rating_count', 'rating')
            ->map(fn (int|string $count): int => (int) $count);

        return [
            'stats' => [
                Stat::make('Visit responses', number_format($visitCount)),
                Stat::make('Visit average', number_format($visitAverage, 1).' / 5'),
                Stat::make('Frame responses', number_format($frameCount)),
                Stat::make('Frame average', number_format($frameAverage, 1).' / 5'),
            ],
            'sections' => [
                [
                    'title' => 'Visit rating distribution',
                    'description' => 'Star values from visible and hidden visit ratings; comments are never shown here.',
                    'rows' => $this->distributionRows($visitDistribution, $visitCount),
                    'has_data' => $visitCount > 0,
                ],
                [
                    'title' => 'Frame rating distribution',
                    'description' => 'Star values from visible and hidden frame ratings; comments are never shown here.',
                    'rows' => $this->distributionRows($frameDistribution, $frameCount),
                    'has_data' => $frameCount > 0,
                ],
            ],
        ];
    }

    /**
     * @param  Collection<int|string, int>  $distribution
     * @return array<int, array{label: string, value: int, percentage: int}>
     */
    private function distributionRows(Collection $distribution, int $total): array
    {
        return collect(range(1, 5))
            ->map(fn (int $rating): array => [
                'label' => $rating === 1 ? '1 star' : "{$rating} stars",
                'value' => $distribution->get($rating, 0),
                'percentage' => $this->percentage($distribution->get($rating, 0), $total),
            ])
            ->all();
    }
}
