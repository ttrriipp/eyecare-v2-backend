<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddFrameReservationItem
{
    /**
     * Add another candidate frame to an existing reservation — the same
     * reservation, not a new one, since an appointment only ever gets one.
     * If the reservation is already Prepared, the new item's stock is
     * allocated immediately too, so every item stays consistent with the
     * reservation's overall status.
     */
    public function handle(FrameReservation $reservation, int $productVariantId): FrameReservationItem
    {
        if (! in_array($reservation->status, [ReservationStatus::Requested, ReservationStatus::Prepared, ReservationStatus::Released], true)) {
            throw ValidationException::withMessages([
                'reservation' => ['Frames can only be added to a requested, prepared, or released reservation.'],
            ]);
        }

        $this->validateVariant($reservation, $productVariantId);

        return DB::transaction(function () use ($reservation, $productVariantId): FrameReservationItem {
            $reservation = FrameReservation::query()->lockForUpdate()->findOrFail($reservation->id);

            // Reactivate released reservation
            if ($reservation->status === ReservationStatus::Released) {
                $reservation->update([
                    'status' => ReservationStatus::Requested,
                    'released_at' => null,
                    'released_by' => null,
                    'release_reason' => null,
                ]);
            }

            $item = FrameReservationItem::query()->create([
                'frame_reservation_id' => $reservation->id,
                'product_variant_id' => $productVariantId,
            ]);

            if ($reservation->status === ReservationStatus::Prepared) {
                $this->allocateStock($reservation, $productVariantId);
            }

            return $item->fresh();
        });
    }

    private function validateVariant(FrameReservation $reservation, int $productVariantId): void
    {
        $alreadyIncluded = $reservation->items()
            ->where('product_variant_id', $productVariantId)
            ->exists();

        if ($alreadyIncluded) {
            throw ValidationException::withMessages([
                'product_variant_id' => ['This variant is already part of the reservation.'],
            ]);
        }

        $variant = ProductVariant::query()->with('product')->find($productVariantId);
        $product = $variant?->product;

        if ($variant === null || $product === null || $product->product_type !== 'frame' || ! $product->is_active || ! $variant->is_active) {
            throw ValidationException::withMessages([
                'product_variant_id' => ['This variant is not an active frame variant.'],
            ]);
        }
    }

    private function allocateStock(FrameReservation $reservation, int $productVariantId): void
    {
        $variant = ProductVariant::query()->lockForUpdate()->findOrFail($productVariantId);

        if ($variant->stock_quantity < 1) {
            throw ValidationException::withMessages([
                'product_variant_id' => ['This variant is no longer available.'],
            ]);
        }

        $allocationType = InventoryMovementType::query()->firstOrCreate(['name' => 'reservation_allocation']);
        $previousStock = $variant->stock_quantity;
        $variant->decrement('stock_quantity');

        InventoryMovement::create([
            'product_variant_id' => $variant->id,
            'reservation_id' => $reservation->id,
            'inventory_movement_type_id' => $allocationType->id,
            'quantity_change' => -1,
            'previous_stock' => $previousStock,
            'new_stock' => $variant->fresh()->stock_quantity,
            'created_by' => auth()->id(),
            'notes' => "Allocation for reservation #{$reservation->id} (added item)",
        ]);
    }
}
