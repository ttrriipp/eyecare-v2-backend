<?php

namespace App\Actions\Inventory;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReceiveFrameStock
{
    /**
     * Receive frame stock into a non-expiring batch.
     *
     * @throws AuthorizationException when the receiver is not panel staff.
     * @throws ValidationException when the batch details are invalid.
     */
    public function handle(
        ProductVariant $variant,
        int $quantity,
        User $receiver,
        ?string $batchNumber = null,
        ?string $sourceReference = null,
        ?string $notes = null,
        ?string $purchasedAt = null,
    ): InventoryMovement {
        if (! $receiver->hasPanelRole()) {
            throw new AuthorizationException('Only panel staff can receive inventory.');
        }

        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity must be positive.'],
            ]);
        }

        $variant->load('product');

        if (! $variant->isFrame()) {
            throw ValidationException::withMessages([
                'product_variant_id' => ['Only frame variants can receive frame batches.'],
            ]);
        }

        $batchNumber = $this->normalizeBatchNumber($batchNumber, $receiver);
        $purchasedAt = $this->normalizePurchasedAt($purchasedAt);

        return DB::transaction(function () use (
            $variant,
            $quantity,
            $batchNumber,
            $receiver,
            $sourceReference,
            $notes,
            $purchasedAt,
        ): InventoryMovement {
            $lockedVariant = ProductVariant::query()
                ->lockForUpdate()
                ->findOrFail($variant->id);
            $lockedVariant->load('product');

            if (! $lockedVariant->isFrame()) {
                throw ValidationException::withMessages([
                    'product_variant_id' => ['Only frame variants can receive frame batches.'],
                ]);
            }

            $batchNumber ??= $this->generateBatchNumber($lockedVariant, $purchasedAt);

            $previousStock = (int) $lockedVariant->stock_quantity;
            $newStock = $previousStock + $quantity;

            $lockedVariant->update(['stock_quantity' => $newStock]);

            $batch = InventoryLot::query()
                ->where('product_variant_id', $lockedVariant->id)
                ->where('lot_number', $batchNumber)
                ->lockForUpdate()
                ->first();

            if ($batch === null) {
                $batch = InventoryLot::query()->create([
                    'product_variant_id' => $lockedVariant->id,
                    'lot_number' => $batchNumber,
                    'expires_on' => null,
                    'received_quantity' => $quantity,
                    'quantity_on_hand' => $quantity,
                    'received_at' => now(),
                    'purchased_at' => $purchasedAt,
                    'received_by' => $receiver->id,
                    'source_reference' => $this->normalizeOptionalText($sourceReference),
                ]);
            } else {
                if ($batch->expires_on !== null) {
                    throw ValidationException::withMessages([
                        'batch_number' => [
                            "Batch {$batchNumber} is already configured with an expiry date.",
                        ],
                    ]);
                }

                $batch->update([
                    'received_quantity' => $batch->received_quantity + $quantity,
                    'quantity_on_hand' => $batch->quantity_on_hand + $quantity,
                    'purchased_at' => min(
                        $batch->purchased_at?->toDateString() ?? $purchasedAt,
                        $purchasedAt,
                    ),
                ]);
            }

            $movement = InventoryMovement::query()->create([
                'product_variant_id' => $lockedVariant->id,
                'inventory_lot_id' => $batch->id,
                'inventory_movement_type_id' => InventoryMovementType::query()
                    ->firstOrCreate(['name' => 'restock'])->id,
                'quantity_change' => $quantity,
                'purchased_at' => $purchasedAt,
                'previous_stock' => $previousStock,
                'new_stock' => $newStock,
                'created_by' => $receiver->id,
                'notes' => $notes,
            ]);

            app(CreateAuditLog::class)->handle(
                subject: $movement,
                action: AuditEvent::InventoryMovementRecorded,
                metadata: [
                    'type' => 'restock',
                    'quantity_change' => $quantity,
                    'variant_id' => $lockedVariant->id,
                    'inventory_lot_id' => $batch->id,
                    'batch_number' => $batch->lot_number,
                ],
                actorId: $receiver->id,
            );

            return $movement;
        });
    }

    private function normalizeBatchNumber(?string $batchNumber, User $receiver): ?string
    {
        $batchNumber = $batchNumber === null ? null : trim($batchNumber);

        if ($batchNumber === null || $batchNumber === '') {
            return null;
        }

        if (! $receiver->isAdmin()) {
            throw new AuthorizationException('Only administrators can specify a custom frame batch number.');
        }

        if (mb_strlen($batchNumber) > 50) {
            throw ValidationException::withMessages([
                'batch_number' => ['Batch number must be 50 characters or fewer.'],
            ]);
        }

        return $batchNumber;
    }

    private function generateBatchNumber(ProductVariant $variant, string $purchasedAt): string
    {
        $date = CarbonImmutable::parse($purchasedAt)->format('ymd');
        $sequence = 1;

        do {
            $batchNumber = sprintf(
                'FRM-%d-%s-%d',
                $variant->id,
                $date,
                $sequence,
            );
            $sequence++;
        } while (InventoryLot::query()
            ->where('product_variant_id', $variant->id)
            ->where('lot_number', $batchNumber)
            ->exists());

        return $batchNumber;
    }

    private function normalizePurchasedAt(?string $purchasedAt): string
    {
        try {
            return CarbonImmutable::parse($purchasedAt ?? now()->toDateString())->toDateString();
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'purchased_at' => ['Date received must be a valid date.'],
            ]);
        }
    }

    private function normalizeOptionalText(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        if ($value !== null && mb_strlen($value) > 255) {
            throw ValidationException::withMessages([
                'source_reference' => ['Reference must be 255 characters or fewer.'],
            ]);
        }

        return $value === '' ? null : $value;
    }
}
