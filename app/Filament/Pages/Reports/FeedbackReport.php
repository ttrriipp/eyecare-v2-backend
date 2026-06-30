<?php

namespace App\Filament\Pages\Reports;

use App\Models\Feedback;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FeedbackReport extends BaseReport
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Feedback Report';

    /**
     * @return array<int, Stat>
     */
    public function getStats(): array
    {
        $query = Feedback::query()
            ->when($this->dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateUntil, fn ($q) => $q->whereDate('created_at', '<=', $this->dateUntil));

        $total = (clone $query)->count();
        $avg = $total > 0 ? round((float) (clone $query)->avg('rating'), 1) : 0;

        return [
            Stat::make('Total feedback', number_format($total)),
            Stat::make('Average rating', $avg.' / 5')->color($avg >= 4 ? 'success' : ($avg >= 3 ? 'warning' : 'danger')),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function getBreakdown(): array
    {
        $query = Feedback::query()
            ->when($this->dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateUntil, fn ($q) => $q->whereDate('created_at', '<=', $this->dateUntil));

        $counts = (clone $query)->selectRaw('rating, count(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $breakdown = [];
        for ($star = 5; $star >= 1; $star--) {
            $breakdown["$star star"] = (int) ($counts[$star] ?? 0);
        }

        return $breakdown;
    }
}
