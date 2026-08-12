<?php

namespace App\Actions\Reservations;

use App\Models\FrameReservation;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FrameReservationStock
{
    /**
     * Allocate one unit of the given variant for the reservation.
     *
     * Fails if stock_quantity < 1. Writes exactly one
     * reservation_allocation movement.
     */
    public function allocate(
        FrameReservation $reservation,
        int $productVariantId,
    ): void {
        DB::transaction(function () use ($reservation, $productVariantId): void {
            $variant = ProductVariant::query()
                ->whereKey($productVariantId)
                ->lockForUpdate()
                ->first();

            if ($variant === null || $variant->stock_quantity < 1) {
                throw ValidationException::withMessages([
                    'items' => ["Insufficient stock for variant {$productVariantId}."],
                ]);
            }

            $allocationType = InventoryMovementType::query()
                ->firstOrCreate(['name' => 'reservation_allocation']);

            $previousStock = $variant->stock_quantity;
            $variant->decrement('stock_quantity');

            InventoryMovement::query()->create([
                'product_variant_id' => $variant->id,
                'reservation_id' => $reservation->id,
                'inventory_movement_type_id' => $allocationType->id,
                'quantity_change' => -1,
                'previous_stock' => $previousStock,
                'new_stock' => $variant->fresh()->stock_quantity,
                'created_by' => auth()->id(),
                'notes' => "Allocation for reservation #{$reservation->id}",
            ]);
        });
    }

    /**
     * Release one unit of the given variant for the reservation.
     *
     * Writes exactly one reservation_release movement.
     */
    public function release(
        FrameReservation $reservation,
        int $productVariantId,
    ): void {
        DB::transaction(function () use ($reservation, $productVariantId): void {
            $variant = ProductVariant::query()
                ->whereKey($productVariantId)
                ->lockForUpdate()
                ->first();

            if ($variant === null) {
                return;
            }

            $releaseType = InventoryMovementType::query()
                ->firstOrCreate(['name' => 'reservation_release']);

            $previousStock = $variant->stock_quantity;
            $variant->increment('stock_quantity');

            InventoryMovement::query()->create([
                'product_variant_id' => $variant->id,
                'reservation_id' => $reservation->id,
                'inventory_movement_type_id' => $releaseType->id,
                'quantity_change' => 1,
                'previous_stock' => $previousStock,
                'new_stock' => $variant->fresh()->stock_quantity,
                'created_by' => auth()->id(),
                'notes' => "Release for reservation #{$reservation->id}",
            ]);
        });
    }
}
