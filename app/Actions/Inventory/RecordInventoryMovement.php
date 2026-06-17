<?php

namespace App\Actions\Inventory;

use App\Actions\Audit\CreateAuditLog;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

class RecordInventoryMovement
{
    /**
     * Record an inventory movement for a variant and adjust the stock quantity.
     *
     * Wraps both writes in a single transaction to prevent race conditions.
     * When quantityChange is negative (a deduction), an insufficient-stock guard
     * blocks the operation if current stock would go below zero.
     *
     * @throws \RuntimeException when a deduction would result in negative stock.
     */
    public function handle(
        ProductVariant $variant,
        int $quantityChange,
        string $type,
        ?int $orderId = null,
        ?string $notes = null,
    ): InventoryMovement {
        return DB::transaction(function () use ($variant, $quantityChange, $type, $orderId, $notes): InventoryMovement {
            if ($quantityChange < 0) {
                $deduction = abs($quantityChange);

                $affected = ProductVariant::query()
                    ->where('id', $variant->id)
                    ->where('stock_quantity', '>=', $deduction)
                    ->decrement('stock_quantity', $deduction);

                if ($affected === 0) {
                    throw new \RuntimeException(
                        "Insufficient stock for variant #{$variant->id}: cannot deduct {$deduction} unit(s)."
                    );
                }
            } else {
                $variant->increment('stock_quantity', $quantityChange);
            }

            $movement = InventoryMovement::query()->create([
                'product_variant_id' => $variant->id,
                'order_id' => $orderId,
                'inventory_movement_type_id' => InventoryMovementType::query()
                    ->firstOrCreate(['name' => $type])->id,
                'quantity_change' => $quantityChange,
                'notes' => $notes,
            ]);

            app(CreateAuditLog::class)->handle(
                subject: $movement,
                action: 'inventory.movement_recorded',
                metadata: ['type' => $type, 'quantity_change' => $quantityChange, 'variant_id' => $variant->id],
            );

            return $movement;
        });
    }
}
