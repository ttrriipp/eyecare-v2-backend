<?php

namespace App\Filament\Pages\Reports;

use App\Models\Order;
use App\Models\OrderStatus;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use UnitEnum;

class OrdersReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Orders Report';

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
        return 'Orders Report';
    }

    /**
     * @return array<int, Stat>
     */
    public function getStats(): array
    {
        $query = Order::query()
            ->when($this->dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateUntil, fn ($q) => $q->whereDate('created_at', '<=', $this->dateUntil));

        $total = (clone $query)->count();
        $revenue = (float) (clone $query)
            ->whereHas('status', fn ($q) => $q->where('name', 'completed'))
            ->sum('total_amount');

        return [
            Stat::make('Total orders', number_format($total)),
            Stat::make('Completed revenue', '₱'.number_format($revenue, 2))->color('success'),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function getBreakdown(): array
    {
        $query = Order::query()
            ->when($this->dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateUntil, fn ($q) => $q->whereDate('created_at', '<=', $this->dateUntil));

        $statuses = OrderStatus::query()->pluck('name', 'id');
        $counts = (clone $query)->selectRaw('order_status_id, count(*) as total')
            ->groupBy('order_status_id')
            ->pluck('total', 'order_status_id');

        $breakdown = [];
        foreach ($statuses as $id => $name) {
            $breakdown[ucwords(str_replace('_', ' ', $name))] = (int) ($counts[$id] ?? 0);
        }

        return $breakdown;
    }
}
