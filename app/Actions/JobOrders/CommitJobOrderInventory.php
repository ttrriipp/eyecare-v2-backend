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
use Carbon\CarbonImmutable;
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
            $asOf = CarbonImmutable::now();

            foreach ($jobOrder->items->sortBy(fn ($item): int => (int) ($item->product_variant_id ?? 0)) as $item) {
                if ($item->product_variant_id === null) {
                    continue;
                }

                if (in_array($item->product_variant_id, $excludeProductVariantIds ?? [], true)) {
                    continue;
                }

                $variant = ProductVariant::query()
                    ->with('product')
                    ->whereKey($item->product_variant_id)
                    ->lockForUpdate()
                    ->first();

                if ($variant === null) {
                    throw ValidationException::withMessages([
                        'items' => ["Insufficient stock for variant {$item->product_variant_id}."],
                    ]);
                }

                $quantity = (int) $item->quantity;

                if ($variant->product?->product_type === 'contact_lens') {
                    $allocations = $this->contactLensAllocations($variant, $quantity, $asOf);
                    $previousStock = (int) $variant->stock_quantity;

                    foreach ($allocations as $allocation) {
                        $newStock = $previousStock - $allocation['quantity'];
                        $allocation['lot']->update([
                            'quantity_on_hand' => $allocation['lot']->quantity_on_hand - $allocation['quantity'],
                        ]);
                        $variant->update(['stock_quantity' => $newStock]);

                        $this->createCommitmentMovement(
                            variant: $variant,
                            jobOrder: $jobOrder,
                            movementType: $commitmentType,
                            quantity: $allocation['quantity'],
                            previousStock: $previousStock,
                            newStock: $newStock,
                            actorId: $actorId ?? auth()->id(),
                            lot: $allocation['lot'],
                        );

                        $previousStock = $newStock;
                        $movementCount++;
                    }
                } else {
                    if ($variant->stock_quantity < $quantity) {
                        throw ValidationException::withMessages([
                            'items' => ["Insufficient stock for variant {$item->product_variant_id}."],
                        ]);
                    }

                    $previousStock = (int) $variant->stock_quantity;
                    $newStock = $previousStock - $quantity;
                    $variant->update(['stock_quantity' => $newStock]);

                    $this->createCommitmentMovement(
                        variant: $variant,
                        jobOrder: $jobOrder,
                        movementType: $commitmentType,
                        quantity: $quantity,
                        previousStock: $previousStock,
                        newStock: $newStock,
                        actorId: $actorId ?? auth()->id(),
                    );

                    $movementCount++;
                }

                $committedQuantity += $quantity;
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

    /**
     * @return list<array{lot: InventoryLot, quantity: int}>
     */
    private function contactLensAllocations(
        ProductVariant $variant,
        int $quantity,
        CarbonImmutable $asOf,
    ): array {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'items' => ['Order quantities must be positive.'],
            ]);
        }

        $lots = InventoryLot::query()
            ->where('product_variant_id', $variant->id)
            ->available()
            ->notExpired($asOf)
            ->orderBy('expires_on')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $totalLotQuantity = (int) InventoryLot::query()
            ->where('product_variant_id', $variant->id)
            ->sum('quantity_on_hand');
        $availableQuantity = (int) $lots->sum('quantity_on_hand');

        if ($totalLotQuantity !== (int) $variant->stock_quantity) {
            throw ValidationException::withMessages([
                'items' => ["Contact-lens stock for variant {$variant->id} needs lot reconciliation."],
            ]);
        }

        if ($variant->stock_quantity < $quantity || $availableQuantity < $quantity) {
            throw ValidationException::withMessages([
                'items' => ["Insufficient usable stock for contact-lens variant {$variant->id}."],
            ]);
        }

        $remaining = $quantity;
        $allocations = [];

        foreach ($lots as $lot) {
            if ($remaining === 0) {
                break;
            }

            $allocated = min($remaining, (int) $lot->quantity_on_hand);
            $allocations[] = ['lot' => $lot, 'quantity' => $allocated];
            $remaining -= $allocated;
        }

        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'items' => ["Insufficient usable stock for contact-lens variant {$variant->id}."],
            ]);
        }

        return $allocations;
    }

    private function createCommitmentMovement(
        ProductVariant $variant,
        JobOrder $jobOrder,
        InventoryMovementType $movementType,
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
            'inventory_movement_type_id' => $movementType->id,
            'quantity_change' => -$quantity,
            'previous_stock' => $previousStock,
            'new_stock' => $newStock,
            'created_by' => $actorId,
            'notes' => "Commitment for job order #{$jobOrder->job_order_number}",
        ]);
    }
}
