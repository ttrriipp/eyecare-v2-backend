<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\JobOrder;
use App\Models\ProductVariant;
use App\Notifications\FrameReservationStatusChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertFrameReservationToJobOrder
{
    public function handle(FrameReservation $reservation, JobOrder $jobOrder): void
    {
        DB::transaction(function () use ($reservation, $jobOrder): void {
            $reservation = FrameReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $lockedJobOrder = JobOrder::query()->lockForUpdate()->findOrFail($jobOrder->id);

            $linkedOrder = JobOrder::withTrashed()
                ->where('frame_reservation_id', $reservation->id)
                ->lockForUpdate()
                ->first();

            if ($linkedOrder !== null) {
                if ($linkedOrder->id === $lockedJobOrder->id && $reservation->status === ReservationStatus::Converted) {
                    return;
                }

                throw ValidationException::withMessages([
                    'reservation' => ['This frame reservation is already linked to another Optical Order.'],
                ]);
            }

            if ($lockedJobOrder->frame_reservation_id !== null
                && $lockedJobOrder->frame_reservation_id !== $reservation->id) {
                throw ValidationException::withMessages([
                    'job_order' => ['This Optical Order is already linked to another frame reservation.'],
                ]);
            }

            if (! in_array($reservation->status, [
                ReservationStatus::Requested,
                ReservationStatus::Prepared,
                ReservationStatus::TriedOn,
            ], true)) {
                throw ValidationException::withMessages([
                    'reservation' => ['Only requested, prepared, or tried-on reservations can be converted.'],
                ]);
            }

            $hasAllocatedStock = in_array($reservation->status, [ReservationStatus::Prepared, ReservationStatus::TriedOn], true);

            if ($hasAllocatedStock) {
                // Release every reservation allocation. The normal Optical Order
                // commitment runs after this action and commits only quoted items.
                $releaseType = InventoryMovementType::query()->firstOrCreate(['name' => 'reservation_release']);

                foreach ($reservation->items()->lockForUpdate()->get() as $item) {
                    $variant = ProductVariant::query()->lockForUpdate()->findOrFail($item->product_variant_id);

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
                        'notes' => "Release for reservation #{$reservation->id} before Optical Order commitment",
                    ]);
                }
            }

            // Requested reservations have no allocation to release. In both
            // cases, CommitJobOrderInventory handles the quoted catalog items.
            $lockedJobOrder->update(['frame_reservation_id' => $reservation->id]);

            $previousStatus = $reservation->status;
            $reservation->update(['status' => ReservationStatus::Converted]);

            // Notify patient
            $this->notifyPatient($reservation, $previousStatus);
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
