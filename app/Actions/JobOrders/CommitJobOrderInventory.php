<?php

namespace App\Actions\JobOrders;

use App\Enums\JobOrderStatus;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\JobOrder;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommitJobOrderInventory
{
    /**
     * @param  array<int, int>  $selectedLotIds  Explicit lot selections per variant ID.
     */
    public function handle(JobOrder $jobOrder, array $selectedLotIds = []): void
    {
        if ($jobOrder->status !== JobOrderStatus::Queued) {
            throw ValidationException::withMessages([
                'job_order' => ['Only queued job orders can commit inventory.'],
            ]);
        }

        DB::transaction(function () use ($jobOrder, $selectedLotIds): void {
            $commitmentType = InventoryMovementType::query()
                ->firstOrCreate(['name' => 'order_commitment']);

            foreach ($jobOrder->items as $item) {
                if ($item->product_variant_id === null) {
                    continue;
                }

                $variant = ProductVariant::query()
                    ->whereKey($item->product_variant_id)
                    ->lockForUpdate()
                    ->first();

                if ($variant === null || $variant->stock_quantity < $item->quantity) {
                    throw ValidationException::withMessages([
                        'items' => ["Insufficient stock for variant {$item->product_variant_id}."],
                    ]);
                }

                // Lot-aware allocation for contact-lens variants
                $lotId = null;
                if ($variant->product->product_type === 'contact_lens') {
                    $lotId = $this->allocateLot(
                        $variant,
                        $item->quantity,
                        $selectedLotIds[$item->product_variant_id] ?? null,
                    );
                }

                $previousStock = $variant->stock_quantity;
                $variant->decrement('stock_quantity', $item->quantity);

                // Also decrement lot quantity if lot-tracked
                if ($lotId !== null) {
                    InventoryLot::query()
                        ->whereKey($lotId)
                        ->lockForUpdate()
                        ->decrement('quantity_on_hand', $item->quantity);
                }

                InventoryMovement::query()->create([
                    'product_variant_id' => $variant->id,
                    'job_order_id' => $jobOrder->id,
                    'inventory_lot_id' => $lotId,
                    'inventory_movement_type_id' => $commitmentType->id,
                    'quantity_change' => -$item->quantity,
                    'previous_stock' => $previousStock,
                    'new_stock' => $variant->fresh()->stock_quantity,
                    'created_by' => auth()->id(),
                    'notes' => "Commitment for job order #{$jobOrder->job_order_number}",
                ]);
            }
        });
    }

    /**
     * Allocate stock from a lot using FEFO or explicit selection.
     */
    private function allocateLot(ProductVariant $variant, int $quantity, ?int $selectedLotId): int
    {
        if ($selectedLotId !== null) {
            return $this->allocateFromSelectedLot($variant, $quantity, $selectedLotId);
        }

        return $this->allocateByFEFO($variant, $quantity);
    }

    /**
     * Allocate from an explicitly selected eligible lot.
     */
    private function allocateFromSelectedLot(ProductVariant $variant, int $quantity, int $lotId): int
    {
        $lot = InventoryLot::query()
            ->whereKey($lotId)
            ->where('product_variant_id', $variant->id)
            ->lockForUpdate()
            ->first();

        if ($lot === null) {
            throw ValidationException::withMessages([
                'items' => ["Lot {$lotId} does not belong to variant {$variant->id}."],
            ]);
        }

        if ($lot->isExpired()) {
            throw ValidationException::withMessages([
                'items' => ["Lot {$lot->lot_number} is expired."],
            ]);
        }

        if ($lot->quantity_on_hand < $quantity) {
            throw ValidationException::withMessages([
                'items' => ["Insufficient quantity in lot {$lot->lot_number}."],
            ]);
        }

        return $lot->id;
    }

    /**
     * Allocate by first-expiry-first-out among non-expired lots.
     */
    private function allocateByFEFO(ProductVariant $variant, int $quantity): int
    {
        $lot = InventoryLot::query()
            ->where('product_variant_id', $variant->id)
            ->notExpired()
            ->available()
            ->lockForUpdate()
            ->orderBy('expires_on', 'asc')
            ->first();

        if ($lot === null) {
            throw ValidationException::withMessages([
                'items' => ["No non-expired lots available for variant {$variant->id}."],
            ]);
        }

        if ($lot->quantity_on_hand < $quantity) {
            throw ValidationException::withMessages([
                'items' => ["Insufficient quantity in lot {$lot->lot_number} (FEFO)."],
            ]);
        }

        return $lot->id;
    }
}
