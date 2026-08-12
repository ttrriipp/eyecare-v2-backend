<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
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
            // Row-lock the appointment to prevent concurrent reservation creation.
            Appointment::query()
                ->whereKey($appointment->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Check for existing released reservation to reactivate
            $existingReleased = FrameReservation::query()
                ->where('appointment_id', $appointment->id)
                ->where('status', ReservationStatus::Released)
                ->first();

            if ($existingReleased !== null) {
                return $this->reactivateReservation($existingReleased, $items);
            }

            $this->assertNoExistingReservation($appointment);

            $reservation = FrameReservation::query()->create([
                'patient_id' => $patient->id,
                'appointment_id' => $appointment->id,
                'status' => ReservationStatus::Requested,
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

    /**
     * Reactivate a released reservation with new frames.
     * Remove old candidate items and replace with new ones.
     */
    private function reactivateReservation(FrameReservation $reservation, array $items): FrameReservation
    {
        $reservation->update([
            'status' => ReservationStatus::Requested,
            'released_at' => null,
            'released_by' => null,
            'release_reason' => null,
        ]);

        // Remove old candidate items
        $reservation->items()->delete();

        // Add new items
        foreach ($items as $item) {
            FrameReservationItem::query()->create([
                'frame_reservation_id' => $reservation->id,
                'product_variant_id' => $item['product_variant_id'],
            ]);
        }

        return $reservation->load(['items.variant.product', 'appointment']);
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

    /**
     * An appointment can have one active reservation at a time.
     * Released reservations can be reactivated.
     */
    private function assertNoExistingReservation(Appointment $appointment): void
    {
        $hasActive = FrameReservation::query()
            ->where('appointment_id', $appointment->id)
            ->whereNotIn('status', [
                ReservationStatus::Released,
                ReservationStatus::Cancelled,
            ])
            ->exists();

        if ($hasActive) {
            throw ValidationException::withMessages([
                'appointment_id' => ['This appointment already has an active frame reservation.'],
            ]);
        }
    }
}
