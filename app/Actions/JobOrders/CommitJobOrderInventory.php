<?php

namespace App\Actions\JobOrders;

use App\Enums\JobOrderStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\JobOrder;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommitJobOrderInventory
{
    /**
     * @param  array<int, int>  $excludeProductVariantIds  Variants already committed elsewhere in
     *                                                     this same confirmation (e.g. transferred
     *                                                     from a converted frame reservation) —
     *                                                     skipped here to avoid double-committing.
     */
    public function handle(JobOrder $jobOrder, array $excludeProductVariantIds = []): void
    {
        if ($jobOrder->status !== JobOrderStatus::Queued) {
            throw ValidationException::withMessages([
                'job_order' => ['Only queued job orders can commit inventory.'],
            ]);
        }

        DB::transaction(function () use ($jobOrder, $excludeProductVariantIds): void {
            $commitmentType = InventoryMovementType::query()
                ->firstOrCreate(['name' => 'order_commitment']);

            foreach ($jobOrder->items as $item) {
                if ($item->product_variant_id === null) {
                    continue;
                }

                if (in_array($item->product_variant_id, $excludeProductVariantIds, true)) {
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

                $previousStock = $variant->stock_quantity;
                $variant->decrement('stock_quantity', $item->quantity);

                InventoryMovement::query()->create([
                    'product_variant_id' => $variant->id,
                    'job_order_id' => $jobOrder->id,
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
}
