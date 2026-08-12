<?php

namespace App\Actions\Reservations;

use App\Models\Appointment;
use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
use App\Models\Patient;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateFrameReservation
{
    /**
     * @param  array{product_variant_id: int}[]  $items
     */
    public function handle(
        Patient $patient,
        Appointment $appointment,
        array $items,
    ): FrameReservation {
        $this->validateEligibility($patient, $appointment, $items);

        return DB::transaction(function () use ($patient, $appointment, $items): FrameReservation {
            Appointment::query()
                ->whereKey($appointment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $reservation = FrameReservation::query()->create([
                'patient_id' => $patient->id,
                'appointment_id' => $appointment->id,
            ]);

            foreach ($items as $item) {
                FrameReservationItem::query()->create([
                    'frame_reservation_id' => $reservation->id,
                    'product_variant_id' => $item['product_variant_id'],
                ]);
            }

            return $reservation->load(['items.variant.product', 'appointment']);
        });
    }

    private function validateEligibility(Patient $patient, Appointment $appointment, array $items): void
    {
        if ($appointment->patient_id !== $patient->id) {
            throw ValidationException::withMessages([
                'appointment_id' => ['The selected appointment does not belong to you.'],
            ]);
        }

        if ($appointment->trashed()) {
            throw ValidationException::withMessages([
                'appointment_id' => ['The selected appointment is not eligible.'],
            ]);
        }

        $statusName = $appointment->status->name;

        if ($statusName !== 'scheduled') {
            throw ValidationException::withMessages([
                'appointment_id' => ['Only scheduled appointments are eligible for frame reservations.'],
            ]);
        }

        $endTime = $appointment->scheduled_at->addMinutes($appointment->duration_minutes ?? 30);

        if ($endTime->isPast()) {
            throw ValidationException::withMessages([
                'appointment_id' => ['The selected appointment has already ended.'],
            ]);
        }

        $this->validateVariants($items);
    }

    /**
     * @param  array{product_variant_id: int}[]  $items
     */
    private function validateVariants(array $items): void
    {
        if (count($items) < 1 || count($items) > 5) {
            throw ValidationException::withMessages([
                'items' => ['A reservation must have between 1 and 5 frame candidates.'],
            ]);
        }

        $variantIds = array_column($items, 'product_variant_id');
        $uniqueIds = array_unique($variantIds);

        if (count($variantIds) !== count($uniqueIds)) {
            throw ValidationException::withMessages([
                'items' => ['Each frame variant may only appear once within a reservation.'],
            ]);
        }

        $variants = ProductVariant::query()
            ->whereIn('id', $variantIds)
            ->with('product')
            ->get();

        if ($variants->count() !== count($uniqueIds)) {
            throw ValidationException::withMessages([
                'items' => ['One or more selected variants do not exist.'],
            ]);
        }

        foreach ($variants as $variant) {
            $product = $variant->product;

            if ($product === null || $product->product_type !== 'frame' || ! $product->is_active || ! $variant->is_active) {
                throw ValidationException::withMessages([
                    'items' => ["Variant {$variant->id} is not an active frame variant."],
                ]);
            }
        }
    }
}
