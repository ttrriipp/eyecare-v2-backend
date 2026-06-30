<?php

namespace App\Filament\Pages\Reports;

use App\Models\ProductVariant;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class ReorderReport extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ShoppingCart;

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Reorder Report';

    protected string $view = 'filament.pages.reports.reorder';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isAdmin() || $user?->role?->name === 'staff';
    }

    public function getItems(): Collection
    {
        return ProductVariant::query()
            ->where('low_stock_threshold', '>', 0)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->with('product:id,name,brand_id', 'product.brand:id,name,supplier_contact')
            ->get()
            ->map(fn (ProductVariant $v) => [
                'product_id' => $v->product?->id,
                'product' => $v->product?->name ?? '—',
                'variant' => $v->name,
                'sku' => $v->sku,
                'stock' => $v->stock_quantity,
                'threshold' => $v->low_stock_threshold,
                'deficit' => $v->low_stock_threshold - $v->stock_quantity,
                'supplier' => $v->product?->brand?->supplier_contact ?? '—',
            ])
            ->sortByDesc('deficit')
            ->values();
    }

    public function exportCsv(): StreamedResponse
    {
        $items = $this->getItems();

        return response()->streamDownload(function () use ($items): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Product', 'Variant', 'SKU', 'Stock', 'Threshold', 'Deficit', 'Supplier Contact']);
            foreach ($items as $item) {
                fputcsv($handle, array_values($item));
            }
            fclose($handle);
        }, 'reorder_report_'.now()->format('Y_m_d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
