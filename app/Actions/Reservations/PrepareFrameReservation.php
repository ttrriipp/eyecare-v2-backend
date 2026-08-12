<?php

namespace App\Actions\Reservations;

use App\Actions\Appointments\ClinicSchedule;
use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\ProductVariant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrepareFrameReservation
{
    public function handle(FrameReservation $reservation): FrameReservation
    {
        if ($reservation->status !== ReservationStatus::Requested) {
            throw ValidationException::withMessages([
                'reservation' => ['Only requested reservations can be prepared.'],
            ]);
        }

        // Warn if appointment is more than 7 days away
        $appointment = $reservation->appointment;

        if ($appointment !== null && $appointment->scheduled_at?->isFuture()) {
            $daysUntilAppointment = now()->diffInDays($appointment->scheduled_at, false);

            if ($daysUntilAppointment > 7) {
                throw ValidationException::withMessages([
                    'reservation' => ['Preparing stock more than 7 days before the appointment is not recommended. The reservation will expire at the end of the appointment day.'],
                ]);
            }
        }

        return DB::transaction(function () use ($reservation): FrameReservation {
            $reservation->load(['items', 'appointment']);

            $allocationType = InventoryMovementType::query()
                ->firstOrCreate(['name' => 'reservation_allocation']);

            // Allocate stock for each item under a row lock
            foreach ($reservation->items as $item) {
                $variant = ProductVariant::query()
                    ->whereKey($item->product_variant_id)
                    ->lockForUpdate()
                    ->first();

                if ($variant === null || $variant->stock_quantity < 1) {
                    throw ValidationException::withMessages([
                        'items' => ["Variant {$item->product_variant_id} is no longer available."],
                    ]);
                }

                $previousStock = $variant->stock_quantity;
                $variant->decrement('stock_quantity');

                // Record inventory movement
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
            }

            $reservation->update([
                'status' => ReservationStatus::Prepared,
                'expires_at' => $this->resolveExpiry($reservation),
            ]);

            return $reservation->fresh();
        });
    }

    /**
     * Prepared stock is held until the end of the appointment's clinic day —
     * if the patient doesn't show, reservations:expire releases it.
     */
    private function resolveExpiry(FrameReservation $reservation): ?Carbon
    {
        $appointmentDate = $reservation->appointment?->scheduled_at;

        if ($appointmentDate === null) {
            return null;
        }

        $schedule = ClinicSchedule::forDate($appointmentDate);

        return Carbon::parse($appointmentDate->toDateString().' '.$schedule->closeTime);
    }
}
