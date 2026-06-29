<?php

namespace App\Filament\Pages\Reports;

use App\Models\Feedback;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use UnitEnum;

class FeedbackReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Feedback Report';

    protected string $view = 'filament.pages.reports.report';

    #[Url]
    public ?string $dateFrom = null;

    #[Url]
    public ?string $dateUntil = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->dateFrom ??= now()->startOfMonth()->toDateString();
        $this->dateUntil ??= now()->toDateString();
    }

    public function filtersSchema(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('dateFrom')->label('From')->default(now()->startOfMonth()),
            DatePicker::make('dateUntil')->label('Until')->default(now()),
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Feedback Report';
    }

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
