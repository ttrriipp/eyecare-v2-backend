<?php

namespace App\Actions\JobOrders;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\JobOrderStatus;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\JobOrder;
use App\Models\ProductVariant;
use App\Models\User;
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

    public function handle(JobOrder $jobOrder, string $statusName, ?User $actor = null): JobOrder
    {
        $newStatus = JobOrderStatus::from($statusName);

        return DB::transaction(function () use ($jobOrder, $newStatus, $actor): JobOrder {
            $lockedJobOrder = JobOrder::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($jobOrder->id);
            $currentStatus = $lockedJobOrder->status->value;
            $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];

            if (! in_array($newStatus->value, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot transition job order from '{$currentStatus}' to '{$newStatus->value}'."],
                ]);
            }

            if (
                in_array($newStatus, [JobOrderStatus::ReadyForDispensing, JobOrderStatus::Dispensed], true)
                && blank($lockedJobOrder->supplier_invoice_number)
                && $lockedJobOrder->uses_external_supplier
            ) {
                throw ValidationException::withMessages([
                    'supplier_invoice_number' => ['Enter the supplier invoice number before marking this job order ready.'],
                ]);
            }

            $attributes = ['status' => $newStatus];

            match ($newStatus) {
                JobOrderStatus::InProgress => $attributes['started_at'] = now(),
                JobOrderStatus::ReadyForDispensing => $attributes['ready_at'] = now(),
                JobOrderStatus::Dispensed => $attributes['dispensed_at'] = now(),
                JobOrderStatus::Cancelled => $attributes['cancelled_at'] = now(),
                default => null,
            };

            $lockedJobOrder->update($attributes);

            $reversedQuantity = $newStatus === JobOrderStatus::Cancelled
                ? $this->reverseInventory($lockedJobOrder, $actor?->id ?? auth()->id())
                : 0;

            app(CreateAuditLog::class)->handle(
                subject: $lockedJobOrder,
                action: $newStatus === JobOrderStatus::Cancelled
                    ? AuditEvent::JobOrderCancelled
                    : AuditEvent::JobOrderStatusChanged,
                metadata: [
                    'from' => $currentStatus,
                    'to' => $newStatus->value,
                    'reversed_quantity' => $reversedQuantity,
                ],
                actorId: $actor?->id ?? auth()->id(),
            );

            return $lockedJobOrder->fresh();
        });
    }

    private function reverseInventory(JobOrder $jobOrder, ?int $actorId): int
    {
        $reversalType = InventoryMovementType::query()
            ->firstOrCreate(['name' => 'order_reversal']);

        $commitmentType = InventoryMovementType::query()
            ->where('name', 'order_commitment')
            ->first();

        if ($commitmentType === null) {
            return 0;
        }

        $reversedQuantity = 0;

        foreach ($jobOrder->items->sortBy(fn ($item): int => (int) ($item->product_variant_id ?? 0)) as $item) {
            if ($item->product_variant_id === null) {
                continue;
            }

            $commitmentMovements = InventoryMovement::query()
                ->where('job_order_id', $jobOrder->id)
                ->where('product_variant_id', $item->product_variant_id)
                ->where('inventory_movement_type_id', $commitmentType->id)
                ->get();

            $reversedQty = InventoryMovement::query()
                ->where('job_order_id', $jobOrder->id)
                ->where('product_variant_id', $item->product_variant_id)
                ->where('inventory_movement_type_id', $reversalType->id)
                ->sum('quantity_change');

            $committedQty = $commitmentMovements->sum('quantity_change');
            $netCommitment = abs($committedQty) - abs($reversedQty);

            if ($netCommitment <= 0) {
                continue;
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
                'created_by' => $actorId,
                'notes' => "Reversal for cancelled job order #{$jobOrder->job_order_number}",
            ]);

            $reversedQuantity += (int) $netCommitment;
        }

        if ($reversedQuantity > 0) {
            app(CreateAuditLog::class)->handle(
                subject: $jobOrder,
                action: AuditEvent::InventoryReversed,
                metadata: ['quantity' => $reversedQuantity],
                actorId: $actorId,
            );
        }

        return $reversedQuantity;
    }
}
