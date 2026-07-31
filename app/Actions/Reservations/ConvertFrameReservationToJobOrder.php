<?php

namespace App\Actions\Reservations;

use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Enums\FrameReservationStatus;
use App\Enums\InventoryMovementType;
use App\Models\FrameReservation;
use App\Models\InventoryMovement;
use App\Models\JobOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertFrameReservationToJobOrder
{
    public function handle(FrameReservation $reservation, JobOrder $jobOrder): void
    {
        if ($reservation->status !== FrameReservationStatus::Prepared
            && $reservation->status !== FrameReservationStatus::Requested) {
            throw ValidationException::withMessages([
                'reservation' => ['Only requested or prepared reservations can be converted.'],
            ]);
        }

        DB::transaction(function () use ($reservation, $jobOrder) {
            $reservation->lockForUpdate();

            if ($reservation->status === FrameReservationStatus::Prepared) {
                // Transfer: release reservation allocation + commit order
                // Net-zero stock change for prepared variants
                foreach ($reservation->items as $item) {
                    $variant = $item->productVariant;
                    $variant->lockForUpdate();

                    // Release the reservation allocation
                    InventoryMovement::create([
                        'product_variant_id' => $variant->id,
                        'reservation_id' => $reservation->id,
                        'inventory_movement_type_id' => InventoryMovementType::ReservationRelease->value,
                        'quantity_change' => 1,
                        'previous_stock' => $variant->stock_quantity,
                        'new_stock' => $variant->stock_quantity + 1,
                        'created_by' => auth()->id(),
                    ]);

                    // Commit to order (decrement again)
                    $variant->decrement('stock_quantity');

                    InventoryMovement::create([
                        'product_variant_id' => $variant->id,
                        'job_order_id' => $jobOrder->id,
                        'inventory_movement_type_id' => InventoryMovementType::OrderCommitment->value,
                        'quantity_change' => -1,
                        'previous_stock' => $variant->stock_quantity + 1,
                        'new_stock' => $variant->stock_quantity,
                        'created_by' => auth()->id(),
                    ]);
                }
            } else {
                // Unprepared: just commit normally
                app(CommitJobOrderInventory::class)->handle($jobOrder);
            }

            // Link and convert
            $jobOrder->update(['frame_reservation_id' => $reservation->id]);
            $reservation->update(['status' => FrameReservationStatus::Converted]);
        });
    }
}
