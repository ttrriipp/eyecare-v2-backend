<?php

namespace App\Actions\Inventory;

use App\Models\InventoryMovement;
use App\Models\ProductVariant;

class RecordInventoryMovement
{
    /**
     * Record an inventory movement for a variant and adjust the stock quantity.
     */
    public function handle(
        ProductVariant $variant,
        int $quantityChange,
        string $type,
        ?int $orderId = null,
        ?string $notes = null,
    ): InventoryMovement {
        $movement = InventoryMovement::query()->create([
            'product_variant_id' => $variant->id,
            'order_id' => $orderId,
            'quantity_change' => $quantityChange,
            'type' => $type,
            'notes' => $notes,
        ]);

        $variant->increment('stock_quantity', $quantityChange);

        return $movement;
    }
}
