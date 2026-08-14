<?php

namespace App\Actions\JobOrders;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
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
     * @param  list<int>|null  $excludeProductVariantIds
     */
    public function handle(
        JobOrder $jobOrder,
        ?array $excludeProductVariantIds = null,
        ?int $actorId = null,
    ): void {
        if ($jobOrder->status !== JobOrderStatus::Queued) {
            throw ValidationException::withMessages([
                'job_order' => ['Only queued job orders can commit inventory.'],
            ]);
        }

        DB::transaction(function () use ($jobOrder, $excludeProductVariantIds, $actorId): void {
            $commitmentType = InventoryMovementType::query()
                ->firstOrCreate(['name' => 'order_commitment']);

            $movementCount = 0;
            $committedQuantity = 0;

            foreach ($jobOrder->items->sortBy(fn ($item): int => (int) ($item->product_variant_id ?? 0)) as $item) {
                if ($item->product_variant_id === null) {
                    continue;
                }

                if (in_array($item->product_variant_id, $excludeProductVariantIds ?? [], true)) {
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
                    'created_by' => $actorId ?? auth()->id(),
                    'notes' => "Commitment for job order #{$jobOrder->job_order_number}",
                ]);

                $movementCount++;
                $committedQuantity += (int) $item->quantity;
            }

            if ($movementCount > 0) {
                app(CreateAuditLog::class)->handle(
                    subject: $jobOrder,
                    action: AuditEvent::InventoryCommitted,
                    metadata: [
                        'movement_count' => $movementCount,
                        'quantity' => $committedQuantity,
                    ],
                    actorId: $actorId ?? auth()->id(),
                );
            }
        });
    }
}
