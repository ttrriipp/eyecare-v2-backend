<?php

namespace App\Filament\Clusters\Reports\Pages;

use Filament\Widgets\StatsOverviewWidget\Stat;

class FinancialReport extends ReportsClusterPage
{
    protected static ?string $title = 'Financial';

    protected static ?string $navigationLabel = 'Financial';

    protected static ?int $navigationSort = 1;

    /**
     * The financial query is added in the financial report task. This keeps the
     * shared navigation and filter shell independently testable.
     *
     * @return array{stats: array<int, Stat>, sections: array<int, array<string, mixed>>}
     */
    protected function buildReport(): array
    {
        return [
            'stats' => [],
            'sections' => [],
        ];
    }
}
