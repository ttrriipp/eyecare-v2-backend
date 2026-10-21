<?php

namespace Database\Seeders;

use App\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

class CatalogStockReceiptReferencesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        InventoryMovement::query()
            ->where('notes', 'Opening stock seeded from catalog.')
            ->where('quantity_change', '>', 0)
            ->whereHas('movementType', fn (Builder $query): Builder => $query->where('name', 'restock'))
            ->whereHas('lot', fn (Builder $query): Builder => $query->where('source_reference', 'Catalog opening stock'))
            ->lazyById()
            ->each(function (InventoryMovement $receipt): void {
                $references = [];

                if (blank($receipt->purchase_order_number)
                    || $receipt->purchase_order_number === sprintf('PO-SEED-%06d', $receipt->id)) {
                    $references['purchase_order_number'] = sprintf('PO-%06d', $receipt->id);
                }

                if (blank($receipt->delivery_receipt_number)
                    || $receipt->delivery_receipt_number === sprintf('DR-SEED-%06d', $receipt->id)) {
                    $references['delivery_receipt_number'] = sprintf('DR-%06d', $receipt->id);
                }

                if ($references !== []) {
                    $receipt->update($references);
                }
            });
    }
}
