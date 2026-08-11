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

class RemoveFrameReservationItem
{
    /**
     * Drop a single candidate frame from a reservation. If the reservation is
     * already Prepared, the item's allocated stock is restored first, so
     * removal never leaks a decremented unit.
     *
     * Removing the last frame automatically releases the reservation with
     * audit information.
     */
    public function handle(FrameReservation $reservation, FrameReservationItem $item): void
    {
        if ($reservation->status === ReservationStatus::Converted) {
            throw ValidationException::withMessages([
                'reservation' => ['This reservation has been converted to an optical order. Change or cancel the optical order instead.'],
            ]);
        }

        if (! in_array($reservation->status, [ReservationStatus::Requested, ReservationStatus::Prepared], true)) {
            throw ValidationException::withMessages([
                'reservation' => ['Frames can only be removed from a requested or prepared reservation.'],
            ]);
        }

        DB::transaction(function () use ($reservation, $item): void {
            $reservation = FrameReservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($reservation->status === ReservationStatus::Prepared) {
                $this->restoreStock($reservation, $item->product_variant_id);
            }

            $item->delete();

            // If no frames remain, release the reservation
            if ($reservation->items()->count() === 0) {
                $reservation->update([
                    'status' => ReservationStatus::Released,
                    'released_at' => now(),
                    'released_by' => auth()->id(),
                    'release_reason' => 'last_frame_removed',
                ]);
            }
        });
    }

    private function restoreStock(FrameReservation $reservation, int $productVariantId): void
    {
        $variant = ProductVariant::query()->lockForUpdate()->findOrFail($productVariantId);

        $releaseType = InventoryMovementType::query()->firstOrCreate(['name' => 'reservation_release']);
        $previousStock = $variant->stock_quantity;
        $variant->increment('stock_quantity');

        InventoryMovement::create([
            'product_variant_id' => $variant->id,
            'reservation_id' => $reservation->id,
            'inventory_movement_type_id' => $releaseType->id,
            'quantity_change' => 1,
            'previous_stock' => $previousStock,
            'new_stock' => $variant->fresh()->stock_quantity,
            'created_by' => auth()->id(),
            'notes' => "Release for reservation #{$reservation->id} (removed item)",
        ]);
    }
}
