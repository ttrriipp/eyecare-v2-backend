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
    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Reorder Report';

    protected string $view = 'filament.pages.reports.reorder';

    public static function getNavigationBadge(): ?string
    {
        $count = ProductVariant::query()
            ->active()
            ->needsReorder()
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isAdmin() || $user?->role?->name === 'staff';
    }

    public function getItems(): Collection
    {
        return ProductVariant::query()
            ->needsReorder()
            ->with('product:id,name,brand_id', 'product.brand:id,name,supplier_contact')
            ->get()
            ->map(fn (ProductVariant $v) => [
                'product_id' => $v->product?->id,
                'product' => $v->product?->name ?? '—',
                'variant' => $v->name,
                'sku' => $v->sku,
                'stock' => $v->stock_quantity,
                'threshold' => $v->low_stock_threshold,
                'target' => $v->replenishmentTarget(),
                'suggested_reorder_quantity' => $v->suggestedReorderQuantity(),
                'supplier' => $v->product?->brand?->supplier_contact ?? '—',
            ])
            ->sort(function (array $first, array $second): int {
                if ($first['suggested_reorder_quantity'] === $second['suggested_reorder_quantity']) {
                    return $first['sku'] <=> $second['sku'];
                }

                if ($first['suggested_reorder_quantity'] === null) {
                    return 1;
                }

                if ($second['suggested_reorder_quantity'] === null) {
                    return -1;
                }

                return $second['suggested_reorder_quantity'] <=> $first['suggested_reorder_quantity'];
            })
            ->values();
    }

    public function exportCsv(): StreamedResponse
    {
        $items = $this->getItems();

        return response()->streamDownload(function () use ($items): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Product', 'Variant', 'SKU', 'Stock', 'Threshold', 'Target', 'Suggested Reorder', 'Supplier Contact']);
            foreach ($items as $item) {
                fputcsv($handle, [
                    $item['product'],
                    $item['variant'],
                    $item['sku'],
                    $item['stock'],
                    $item['threshold'],
                    $item['target'],
                    $item['suggested_reorder_quantity'],
                    $item['supplier'],
                ]);
            }
            fclose($handle);
        }, 'reorder_report_'.now()->format('Y_m_d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
