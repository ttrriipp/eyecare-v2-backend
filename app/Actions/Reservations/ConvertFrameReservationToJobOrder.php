<?php

namespace App\Actions\Reservations;

use App\Actions\JobOrders\CommitJobOrderInventory;
use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\JobOrder;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertFrameReservationToJobOrder
{
    public function handle(FrameReservation $reservation, JobOrder $jobOrder): void
    {
        $convertibleStatuses = [ReservationStatus::Requested, ReservationStatus::Prepared, ReservationStatus::TriedOn];

        if (! in_array($reservation->status, $convertibleStatuses, true)) {
            throw ValidationException::withMessages([
                'reservation' => ['Only requested, prepared, or tried-on reservations can be converted.'],
            ]);
        }

        DB::transaction(function () use ($reservation, $jobOrder) {
            $reservation = FrameReservation::query()->lockForUpdate()->findOrFail($reservation->id);

            $hasAllocatedStock = in_array($reservation->status, [ReservationStatus::Prepared, ReservationStatus::TriedOn], true);

            if ($hasAllocatedStock) {
                // Transfer: release reservation allocation + commit order.
                // Net-zero stock change for already-allocated variants.
                $releaseType = InventoryMovementType::query()->firstOrCreate(['name' => 'reservation_release']);
                $commitmentType = InventoryMovementType::query()->firstOrCreate(['name' => 'order_commitment']);

                foreach ($reservation->items as $item) {
                    $variant = ProductVariant::query()->lockForUpdate()->findOrFail($item->product_variant_id);

                    // Release the reservation allocation
                    $previousStock = $variant->stock_quantity;
                    $variant->increment('stock_quantity');

                    InventoryMovement::create([
                        'product_variant_id' => $variant->id,
                        'reservation_id' => $reservation->id,
                        'inventory_movement_type_id' => $releaseType->id,
                        'quantity_change' => 1,
                        'previous_stock' => $previousStock,
                        'new_stock' => $variant->stock_quantity,
                        'created_by' => auth()->id(),
                    ]);

                    // Commit to order (decrement again)
                    $variant->decrement('stock_quantity');

                    InventoryMovement::create([
                        'product_variant_id' => $variant->id,
                        'job_order_id' => $jobOrder->id,
                        'inventory_movement_type_id' => $commitmentType->id,
                        'quantity_change' => -1,
                        'previous_stock' => $variant->stock_quantity + 1,
                        'new_stock' => $variant->stock_quantity,
                        'created_by' => auth()->id(),
                    ]);
                }
            } else {
                // Unprepared (Requested): no stock allocated yet — just commit normally
                app(CommitJobOrderInventory::class)->handle($jobOrder);
            }

            // Link and convert
            $jobOrder->update(['frame_reservation_id' => $reservation->id]);
            $reservation->update(['status' => ReservationStatus::Converted]);
        });
    }
}
