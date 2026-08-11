<?php

namespace App\Actions\Quotations;

use App\Enums\ReservationStatus;
use App\Models\FrameReservation;
use App\Models\FrameReservationItem;
use App\Models\JobOrder;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ValidateQuotationFrameReservation
{
    /**
     * Resolve and validate the reservation source for a quotation sale.
     *
     * The persisted quotation source is authoritative. The optional legacy
     * arguments are only used when an older quotation has no source yet.
     *
     * @param  Collection<int, QuotationItem>  $productItems
     */
    public function handle(
        Quotation $quotation,
        Collection $productItems,
        ?int $legacyReservationItemId = null,
        ?int $legacyReservationId = null,
        ?JobOrder $existingOpticalOrder = null,
    ): ?FrameReservation {
        if ($quotation->frame_reservation_id === null
            && $legacyReservationItemId === null
            && $legacyReservationId === null) {
            return null;
        }

        $frameItem = $this->resolveQuotedFrameItem($productItems);
        $reservation = $this->resolveReservation(
            quotation: $quotation,
            legacyReservationItemId: $legacyReservationItemId,
            legacyReservationId: $legacyReservationId,
        );

        if ($reservation->patient_id !== $quotation->patient_id) {
            throw ValidationException::withMessages([
                'frame_reservation_id' => ['The selected frame reservation belongs to another patient.'],
            ]);
        }

        $isAlreadyConvertedForThisOrder = $reservation->status === ReservationStatus::Converted
            && $existingOpticalOrder?->frame_reservation_id === $reservation->id;

        if (! $isAlreadyConvertedForThisOrder
            && ! in_array($reservation->status, [
                ReservationStatus::Requested,
                ReservationStatus::Prepared,
                ReservationStatus::TriedOn,
            ], true)) {
            throw ValidationException::withMessages([
                'frame_reservation_id' => ['Only requested, prepared, or tried-on frame reservations can be converted.'],
            ]);
        }

        $linkedOrder = JobOrder::withTrashed()
            ->where('frame_reservation_id', $reservation->id)
            ->lockForUpdate()
            ->first();

        if ($linkedOrder !== null && $linkedOrder->id !== $existingOpticalOrder?->id) {
            throw ValidationException::withMessages([
                'frame_reservation_id' => ['This frame reservation is already linked to another Optical Order.'],
            ]);
        }

        if ($existingOpticalOrder !== null
            && $existingOpticalOrder->frame_reservation_id !== $reservation->id) {
            throw ValidationException::withMessages([
                'frame_reservation_id' => ['The existing Optical Order is linked to a different frame reservation.'],
            ]);
        }

        if (! $reservation->items()
            ->where('product_variant_id', $frameItem->product_variant_id)
            ->exists()) {
            throw ValidationException::withMessages([
                'frame_reservation_id' => ['The selected frame reservation does not contain the quoted frame variant.'],
            ]);
        }

        return $reservation;
    }

    /**
     * @param  Collection<int, QuotationItem>  $productItems
     */
    private function resolveQuotedFrameItem(Collection $productItems): QuotationItem
    {
        $variantIds = $productItems
            ->pluck('product_variant_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $frameVariantIds = ProductVariant::query()
            ->whereIn('id', $variantIds)
            ->whereHas('product', fn (Builder $query): Builder => $query->where('product_type', 'frame'))
            ->pluck('id');

        $frameItems = $productItems->filter(
            fn (QuotationItem $item): bool => $item->product_variant_id !== null
                && $frameVariantIds->contains((int) $item->product_variant_id),
        )->values();

        if ($frameItems->count() !== 1 || $frameItems->first()->quantity !== 1) {
            throw ValidationException::withMessages([
                'frame_reservation_id' => ['A reservation-backed sale must contain exactly one catalog-backed Frame item with quantity one.'],
            ]);
        }

        return $frameItems->first();
    }

    private function resolveReservation(
        Quotation $quotation,
        ?int $legacyReservationItemId,
        ?int $legacyReservationId,
    ): FrameReservation {
        if ($quotation->frame_reservation_id !== null) {
            $reservation = FrameReservation::query()
                ->lockForUpdate()
                ->find($quotation->frame_reservation_id);

            if ($reservation === null) {
                throw ValidationException::withMessages([
                    'frame_reservation_id' => ['The quotation references a frame reservation that no longer exists.'],
                ]);
            }

            return $reservation;
        }

        if ($legacyReservationItemId !== null) {
            $reservationItem = FrameReservationItem::query()
                ->whereKey($legacyReservationItemId)
                ->lockForUpdate()
                ->first();

            if ($reservationItem === null) {
                throw ValidationException::withMessages([
                    'frame_reservation_item_id' => ['The selected reserved frame no longer exists.'],
                ]);
            }

            $reservation = FrameReservation::query()
                ->lockForUpdate()
                ->find($reservationItem->frame_reservation_id);

            if ($reservation === null) {
                throw ValidationException::withMessages([
                    'frame_reservation_item_id' => ['The selected reserved frame reservation no longer exists.'],
                ]);
            }

            return $reservation;
        }

        $reservation = FrameReservation::query()
            ->lockForUpdate()
            ->find($legacyReservationId);

        if ($reservation === null) {
            throw ValidationException::withMessages([
                'frame_reservation_id' => ['The selected frame reservation does not exist.'],
            ]);
        }

        return $reservation;
    }
}
