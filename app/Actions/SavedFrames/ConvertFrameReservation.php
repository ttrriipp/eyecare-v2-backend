<?php

namespace App\Actions\SavedFrames;

use App\Models\FrameReservation;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\ProductVariant;
use App\Models\SavedFrame;
use Illuminate\Support\Facades\DB;

final class ConvertFrameReservation
{
    public function handle(FrameReservation $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            $reservation->lockForUpdate();

            $items = $reservation->items()
                ->lockForUpdate()
                ->get();

            $patient = $reservation->patient;
            $userId = $patient?->user_id;

            if ($reservation->isHeld()) {
                $this->releaseHeldItems($items);
            }

            if ($userId !== null) {
                $this->createSavedFrames($items, $userId);
            }

            $items->each(fn ($item) => $item->delete());
            $reservation->delete();
        });
    }

    /**
     * Release held items in ascending variant-ID lock order.
     */
    private function releaseHeldItems($items): void
    {
        $sortedItems = $items->sortBy('product_variant_id');

        foreach ($sortedItems as $item) {
            $variant = ProductVariant::query()
                ->where('id', $item->product_variant_id)
                ->lockForUpdate()
                ->first();

            if ($variant === null) {
                continue;
            }

            $previousStock = $variant->stock_quantity;
            $variant->increment('stock_quantity');
            $variant->refresh();

            InventoryMovement::query()->create([
                'product_variant_id' => $variant->id,
                'inventory_movement_type_id' => $this->getReleaseMovementTypeId(),
                'quantity_change' => 1,
                'previous_stock' => $previousStock,
                'new_stock' => $variant->stock_quantity,
                'created_by' => null,
            ]);
        }
    }

    /**
     * Create saved frames using insert-or-ignore semantics.
     * Preserves the reservation item's creation time where practical.
     */
    private function createSavedFrames($items, int $userId): void
    {
        foreach ($items as $item) {
            SavedFrame::query()->insertOrIgnore([
                'user_id' => $userId,
                'product_variant_id' => $item->product_variant_id,
                'created_at' => $item->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function getReleaseMovementTypeId(): int
    {
        return InventoryMovementType::query()
            ->firstOrCreate(['name' => 'reservation_release'])
            ->id;
    }
}
