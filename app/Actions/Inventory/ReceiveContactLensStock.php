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

class ReceiveContactLensStock
{
    /**
     * Receive contact-lens stock into a dated lot.
     *
     * @throws ValidationException when the lot details are invalid or conflict
     *                             with an existing lot.
     */
    public function handle(
        ProductVariant $variant,
        int $quantity,
        string $lotNumber,
        string $expiryMonth,
        User $receiver,
        ?string $sourceReference = null,
        ?string $notes = null,
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

        if ($variant->product?->product_type !== 'contact_lens') {
            throw ValidationException::withMessages([
                'product_variant_id' => ['Only contact-lens variants can receive lot-tracked stock.'],
            ]);
        }

        $lotNumber = $this->normalizeLotNumber($lotNumber);
        $expiresOn = $this->normalizeExpiryMonth($expiryMonth);

        return DB::transaction(function () use (
            $variant,
            $quantity,
            $lotNumber,
            $expiresOn,
            $receiver,
            $sourceReference,
            $notes,
        ): InventoryMovement {
            $lockedVariant = ProductVariant::query()
                ->lockForUpdate()
                ->findOrFail($variant->id);
            $lockedVariant->load('product');

            if ($lockedVariant->product?->product_type !== 'contact_lens') {
                throw ValidationException::withMessages([
                    'product_variant_id' => ['Only contact-lens variants can receive lot-tracked stock.'],
                ]);
            }

            $previousStock = (int) $lockedVariant->stock_quantity;
            $newStock = $previousStock + $quantity;

            $lockedVariant->update(['stock_quantity' => $newStock]);

            $lot = InventoryLot::query()
                ->where('product_variant_id', $lockedVariant->id)
                ->where('lot_number', $lotNumber)
                ->lockForUpdate()
                ->first();

            if ($lot === null) {
                $lot = InventoryLot::query()->create([
                    'product_variant_id' => $lockedVariant->id,
                    'lot_number' => $lotNumber,
                    'expires_on' => $expiresOn->toDateString(),
                    'received_quantity' => $quantity,
                    'quantity_on_hand' => $quantity,
                    'received_at' => now(),
                    'received_by' => $receiver->id,
                    'source_reference' => $this->normalizeOptionalText($sourceReference),
                ]);
            } else {
                if ($lot->expires_on->toDateString() !== $expiresOn->toDateString()) {
                    throw ValidationException::withMessages([
                        'expiry_month' => [
                            "Lot {$lotNumber} already has expiry {$lot->expires_on->format('Y-m')}.",
                        ],
                    ]);
                }

                $lot->update([
                    'received_quantity' => $lot->received_quantity + $quantity,
                    'quantity_on_hand' => $lot->quantity_on_hand + $quantity,
                ]);
            }

            $movement = InventoryMovement::query()->create([
                'product_variant_id' => $lockedVariant->id,
                'inventory_lot_id' => $lot->id,
                'inventory_movement_type_id' => InventoryMovementType::query()
                    ->firstOrCreate(['name' => 'restock'])->id,
                'quantity_change' => $quantity,
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
                    'inventory_lot_id' => $lot->id,
                    'lot_number' => $lot->lot_number,
                    'expires_on' => $lot->expires_on->toDateString(),
                ],
                actorId: $receiver->id,
            );

            return $movement;
        });
    }

    private function normalizeLotNumber(string $lotNumber): string
    {
        $lotNumber = trim($lotNumber);

        if ($lotNumber === '' || mb_strlen($lotNumber) > 50) {
            throw ValidationException::withMessages([
                'lot_number' => ['Lot number is required and must be 50 characters or fewer.'],
            ]);
        }

        return $lotNumber;
    }

    private function normalizeExpiryMonth(string $expiryMonth): CarbonImmutable
    {
        $expiryMonth = trim($expiryMonth);

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $expiryMonth)) {
            throw ValidationException::withMessages([
                'expiry_month' => ['Expiry must use the YYYY-MM format.'],
            ]);
        }

        $expiresOn = CarbonImmutable::createFromFormat('!Y-m', $expiryMonth)->endOfMonth();

        if ($expiresOn->isBefore(CarbonImmutable::today())) {
            throw ValidationException::withMessages([
                'expiry_month' => ['Expiry month must be current or future.'],
            ]);
        }

        return $expiresOn;
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
