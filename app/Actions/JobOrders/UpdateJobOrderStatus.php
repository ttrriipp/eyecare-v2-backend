<?php

namespace App\Actions\JobOrders;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Enums\JobOrderStatus;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\JobOrder;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
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

            if ($commitmentMovements->isEmpty()) {
                continue;
            }

            $variant = ProductVariant::query()
                ->with('product')
                ->whereKey($item->product_variant_id)
                ->lockForUpdate()
                ->first();

            if ($variant === null) {
                continue;
            }

            if ($variant->product?->product_type === 'contact_lens') {
                $reversedQuantity += $this->reverseContactLensMovements(
                    jobOrder: $jobOrder,
                    variant: $variant,
                    commitmentMovements: $commitmentMovements,
                    reversalType: $reversalType,
                    actorId: $actorId,
                );

                continue;
            }

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

            $previousStock = (int) $variant->stock_quantity;
            $newStock = $previousStock + $netCommitment;
            $variant->update(['stock_quantity' => $newStock]);

            $this->createReversalMovement(
                jobOrder: $jobOrder,
                variant: $variant,
                reversalType: $reversalType,
                quantity: (int) $netCommitment,
                previousStock: $previousStock,
                newStock: $newStock,
                actorId: $actorId,
            );

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

    /**
     * @param  Collection<int, InventoryMovement>  $commitmentMovements
     */
    private function reverseContactLensMovements(
        JobOrder $jobOrder,
        ProductVariant $variant,
        Collection $commitmentMovements,
        InventoryMovementType $reversalType,
        ?int $actorId,
    ): int {
        $reversalMovements = InventoryMovement::query()
            ->where('job_order_id', $jobOrder->id)
            ->where('product_variant_id', $variant->id)
            ->where('inventory_movement_type_id', $reversalType->id)
            ->get();
        $commitmentsByLot = $commitmentMovements
            ->groupBy(fn (InventoryMovement $movement): int => (int) ($movement->inventory_lot_id ?? 0));
        $reversalsByLot = $reversalMovements
            ->groupBy(fn (InventoryMovement $movement): int => (int) ($movement->inventory_lot_id ?? 0));
        $totalLotQuantity = (int) InventoryLot::query()
            ->where('product_variant_id', $variant->id)
            ->sum('quantity_on_hand');

        if ($totalLotQuantity !== (int) $variant->stock_quantity) {
            throw ValidationException::withMessages([
                'inventory' => ["Contact-lens stock for variant {$variant->id} needs lot reconciliation."],
            ]);
        }

        $reversedQuantity = 0;

        foreach ($commitmentsByLot as $lotId => $lotCommitments) {
            $lotId = (int) $lotId;

            if ($lotId === 0) {
                throw ValidationException::withMessages([
                    'inventory' => ["The contact-lens commitment for variant {$variant->id} has no source lot."],
                ]);
            }

            $committedQuantity = abs((int) $lotCommitments->sum('quantity_change'));
            $alreadyReversed = abs((int) ($reversalsByLot->get($lotId)?->sum('quantity_change') ?? 0));
            $netQuantity = $committedQuantity - $alreadyReversed;

            if ($netQuantity <= 0) {
                continue;
            }

            $lot = InventoryLot::query()
                ->whereKey($lotId)
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->first();

            if ($lot === null) {
                throw ValidationException::withMessages([
                    'inventory' => ["The source lot for contact-lens variant {$variant->id} no longer exists."],
                ]);
            }

            $previousStock = (int) $variant->stock_quantity;
            $newStock = $previousStock + $netQuantity;
            $lot->update(['quantity_on_hand' => $lot->quantity_on_hand + $netQuantity]);
            $variant->update(['stock_quantity' => $newStock]);

            $this->createReversalMovement(
                jobOrder: $jobOrder,
                variant: $variant,
                reversalType: $reversalType,
                quantity: $netQuantity,
                previousStock: $previousStock,
                newStock: $newStock,
                actorId: $actorId,
                lot: $lot,
            );

            $reversedQuantity += $netQuantity;
        }

        return $reversedQuantity;
    }

    private function createReversalMovement(
        JobOrder $jobOrder,
        ProductVariant $variant,
        InventoryMovementType $reversalType,
        int $quantity,
        int $previousStock,
        int $newStock,
        ?int $actorId,
        ?InventoryLot $lot = null,
    ): InventoryMovement {
        return InventoryMovement::query()->create([
            'product_variant_id' => $variant->id,
            'job_order_id' => $jobOrder->id,
            'inventory_lot_id' => $lot?->id,
            'inventory_movement_type_id' => $reversalType->id,
            'quantity_change' => $quantity,
            'previous_stock' => $previousStock,
            'new_stock' => $newStock,
            'created_by' => $actorId,
            'notes' => "Reversal for cancelled job order #{$jobOrder->job_order_number}",
        ]);
    }
}
