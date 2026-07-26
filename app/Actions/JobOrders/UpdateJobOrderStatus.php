<?php

namespace App\Actions\JobOrders;

use App\Enums\JobOrderStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\JobOrder;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateJobOrderStatus
{
    /**
     * Allowed status transitions: current → permitted next statuses.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'queued' => ['in_progress', 'cancelled'],
        'in_progress' => ['ready_for_dispensing', 'cancelled'],
        'ready_for_dispensing' => ['dispensed', 'cancelled'],
        'dispensed' => [],
        'cancelled' => [],
    ];

    public function handle(JobOrder $jobOrder, string $statusName): JobOrder
    {
        $currentStatus = $jobOrder->status->value;
        $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];

        if (! in_array($statusName, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition job order from '{$currentStatus}' to '{$statusName}'."],
            ]);
        }

        $newStatus = JobOrderStatus::from($statusName);

        return DB::transaction(function () use ($jobOrder, $newStatus): JobOrder {
            $attributes = ['status' => $newStatus];

            match ($newStatus) {
                JobOrderStatus::InProgress => $attributes['started_at'] = now(),
                JobOrderStatus::ReadyForDispensing => $attributes['ready_at'] = now(),
                JobOrderStatus::Dispensed => $attributes['dispensed_at'] = now(),
                JobOrderStatus::Cancelled => $attributes['cancelled_at'] = now(),
                default => null,
            };

            $jobOrder->update($attributes);

            // Reverse inventory on cancellation
            if ($newStatus === JobOrderStatus::Cancelled) {
                $this->reverseInventory($jobOrder);
            }

            return $jobOrder->fresh();
        });
    }

    /**
     * Reverse only recorded, unreversed commitments once.
     */
    private function reverseInventory(JobOrder $jobOrder): void
    {
        $reversalType = InventoryMovementType::query()
            ->firstOrCreate(['name' => 'order_reversal']);

        $commitmentType = InventoryMovementType::query()
            ->where('name', 'order_commitment')
            ->first();

        if ($commitmentType === null) {
            return;
        }

        foreach ($jobOrder->items as $item) {
            if ($item->product_variant_id === null) {
                continue;
            }

            // Find unreversed commitments for this variant/job order
            $committedQty = InventoryMovement::query()
                ->where('job_order_id', $jobOrder->id)
                ->where('product_variant_id', $item->product_variant_id)
                ->where('inventory_movement_type_id', $commitmentType->id)
                ->sum('quantity_change');

            $reversedQty = InventoryMovement::query()
                ->where('job_order_id', $jobOrder->id)
                ->where('product_variant_id', $item->product_variant_id)
                ->where('inventory_movement_type_id', $reversalType->id)
                ->sum('quantity_change');

            $netCommitment = abs($committedQty) - abs($reversedQty);

            if ($netCommitment <= 0) {
                continue; // Already fully reversed
            }

            $variant = ProductVariant::query()
                ->whereKey($item->product_variant_id)
                ->lockForUpdate()
                ->first();

            if ($variant === null) {
                continue;
            }

            $previousStock = $variant->stock_quantity;
            $variant->increment('stock_quantity', $netCommitment);

            InventoryMovement::query()->create([
                'product_variant_id' => $variant->id,
                'job_order_id' => $jobOrder->id,
                'inventory_movement_type_id' => $reversalType->id,
                'quantity_change' => $netCommitment,
                'previous_stock' => $previousStock,
                'new_stock' => $variant->fresh()->stock_quantity,
                'created_by' => auth()->id(),
                'notes' => "Reversal for cancelled job order #{$jobOrder->job_order_number}",
            ]);
        }
    }
}
