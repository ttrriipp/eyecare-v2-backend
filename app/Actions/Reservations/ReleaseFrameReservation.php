<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\ProductVariant;
use App\Notifications\FrameReservationStatusChanged;
use Illuminate\Support\Facades\DB;

class ReleaseFrameReservation
{
    public function handle(FrameReservation $reservation, ReservationStatus $targetStatus = ReservationStatus::Released): FrameReservation
    {
        $hasAllocatedStock = in_array($reservation->status, [ReservationStatus::Prepared, ReservationStatus::TriedOn], true);

        if (! $hasAllocatedStock) {
            // Only prepared/tried-on reservations have allocated stock to release
            $reservation->update(['status' => $targetStatus]);

            return $reservation->fresh();
        }

        return DB::transaction(function () use ($reservation, $targetStatus): FrameReservation {
            $reservation->load('items');

            $releaseType = InventoryMovementType::query()
                ->firstOrCreate(['name' => 'reservation_release']);

            // Restore stock for each item under a row lock
            foreach ($reservation->items as $item) {
                $variant = ProductVariant::query()
                    ->whereKey($item->product_variant_id)
                    ->lockForUpdate()
                    ->first();

                if ($variant === null) {
                    continue;
                }

                $previousStock = $variant->stock_quantity;
                $variant->increment('stock_quantity');

                // Record inventory movement
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
            }

            $reservation->update(['status' => $targetStatus]);

            // Notify patient if released (not cancelled)
            if ($targetStatus === ReservationStatus::Released) {
                $this->notifyPatient($reservation, $previousStatus);
            }

            return $reservation->fresh();
        });
    }

    private function notifyPatient(FrameReservation $reservation, ReservationStatus $previousStatus): void
    {
        $patient = $reservation->patient ?? $reservation->appointment?->patient;

        if ($patient !== null && $patient->account !== null) {
            $patient->account->notify(new FrameReservationStatusChanged($reservation, $previousStatus));
        }
    }
}
