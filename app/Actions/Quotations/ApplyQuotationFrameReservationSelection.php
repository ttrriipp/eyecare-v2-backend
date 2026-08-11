<?php

namespace App\Actions\Quotations;

use App\Models\FrameReservationItem;
use App\Models\Patient;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ApplyQuotationFrameReservationSelection
{
    /**
     * Apply a selected reservation item to a quotation's item state.
     *
     * The transient selector contains a FrameReservationItem ID. Only the
     * reservation source ID is persisted on the quotation; the exact variant
     * remains represented by its single catalog-backed Frame item.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{items: array<int, array<string, mixed>>, frame_reservation_id: int|null}
     */
    public function handle(
        Patient $patient,
        array $items,
        ?int $reservationItemId = null,
        ?int $reservationId = null,
        ?int $fallbackReservationId = null,
        ?int $fallbackVariantId = null,
    ): array {
        if ($reservationItemId === null && $reservationId !== null) {
            $reservationItemId = $this->findItemForReservation($patient, $items, $reservationId);
        }

        if ($reservationItemId !== null) {
            $reservationItem = $this->findEligibleReservationItem($patient, $reservationItemId);

            return [
                'items' => $this->replaceFrameLines($items, $reservationItem->variant),
                'frame_reservation_id' => $reservationItem->frame_reservation_id,
            ];
        }

        if ($fallbackReservationId === null || $fallbackVariantId === null) {
            return [
                'items' => $items,
                'frame_reservation_id' => null,
            ];
        }

        $reservationItem = FrameReservationItem::query()
            ->with(['reservation', 'variant.product'])
            ->eligibleForQuotation($patient->id)
            ->where('frame_reservation_id', $fallbackReservationId)
            ->where('product_variant_id', $fallbackVariantId)
            ->lockForUpdate()
            ->first();

        if ($reservationItem === null) {
            return [
                'items' => $items,
                'frame_reservation_id' => null,
            ];
        }

        $incomingFrameVariantIds = $this->frameVariantIds($items);

        if (! $incomingFrameVariantIds->contains($fallbackVariantId)) {
            return [
                'items' => $items,
                'frame_reservation_id' => null,
            ];
        }

        return [
            'items' => $items,
            'frame_reservation_id' => $reservationItem->frame_reservation_id,
        ];
    }

    /**
     * Resolve a legacy reservation-level selector only when it identifies one
     * exact eligible candidate in the submitted quotation frame state.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function findItemForReservation(Patient $patient, array $items, int $reservationId): int
    {
        $frameVariantIds = $this->frameVariantIds($items);
        $query = FrameReservationItem::query()
            ->eligibleForQuotation($patient->id)
            ->where('frame_reservation_id', $reservationId);

        if ($frameVariantIds->isNotEmpty()) {
            $query->whereIn('product_variant_id', $frameVariantIds);
        }

        $matchingItems = $query->get();

        if ($matchingItems->count() !== 1) {
            throw ValidationException::withMessages([
                'frame_reservation_id' => ['The reservation-level selector must identify exactly one eligible reserved frame matching the quotation.'],
            ]);
        }

        return $matchingItems->first()->id;
    }

    private function findEligibleReservationItem(Patient $patient, int $reservationItemId): FrameReservationItem
    {
        $reservationItem = FrameReservationItem::query()
            ->with(['reservation', 'variant.product'])
            ->eligibleForQuotation($patient->id)
            ->whereKey($reservationItemId)
            ->lockForUpdate()
            ->first();

        if ($reservationItem === null) {
            throw ValidationException::withMessages([
                'frame_reservation_item_id' => ['The selected reserved frame is not eligible for this patient.'],
            ]);
        }

        return $reservationItem;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function replaceFrameLines(array $items, ProductVariant $selectedVariant): array
    {
        $frameIndexes = $this->frameIndexes($items);
        $selectedLine = [
            'item_type' => 'catalog',
            'product_variant_id' => $selectedVariant->id,
            'lens_category_id' => null,
            'lens_option_id' => null,
            'service_id' => null,
            'description' => "{$selectedVariant->product->name} — {$selectedVariant->name}",
            'quantity' => 1,
            'unit_price' => $selectedVariant->price,
            'line_total' => number_format((float) $selectedVariant->price, 2),
        ];

        if ($frameIndexes->isEmpty()) {
            return [...$items, $selectedLine];
        }

        $firstFrameIndex = $frameIndexes->first();

        return collect($items)
            ->reject(fn (array $item, int|string $index): bool => $frameIndexes->skip(1)->contains($index))
            ->map(fn (array $item, int|string $index): array => $index === $firstFrameIndex ? $selectedLine : $item)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return Collection<int, int>
     */
    private function frameVariantIds(array $items): Collection
    {
        $variantIds = collect($items)
            ->pluck('product_variant_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($variantIds->isEmpty()) {
            return collect();
        }

        return ProductVariant::query()
            ->with('product')
            ->whereIn('id', $variantIds)
            ->get()
            ->filter(fn (ProductVariant $variant): bool => $variant->product?->product_type === 'frame')
            ->pluck('id')
            ->values();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return Collection<int|string, mixed>
     */
    private function frameIndexes(array $items): Collection
    {
        $frameVariantIds = $this->frameVariantIds($items);

        return collect($items)
            ->filter(fn (array $item): bool => $frameVariantIds->contains((int) ($item['product_variant_id'] ?? 0)))
            ->keys();
    }
}
