<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ReceiveContactLensStock
{
    /**
     * Receive stock into a lot for a contact-lens variant.
     *
     * Atomically updates the lot, aggregate quantity, and movement.
     * Non-contact-lens variants use the simple aggregate-only path.
     */
    public function handle(
        ProductVariant $variant,
        int $quantity,
        string $lotNumber,
        string $expiresOn,
        User $receiver,
        ?string $sourceReference = null,
        ?string $notes = null,
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity must be positive.'],
            ]);
        }

        $isContactLens = $variant->product->product_type === 'contact_lens';

        if ($isContactLens) {
            $this->validateLotData($lotNumber, $expiresOn);
        }

        return DB::transaction(function () use ($variant, $quantity, $lotNumber, $expiresOn, $receiver, $sourceReference, $notes, $isContactLens): InventoryMovement {
            $locked = ProductVariant::query()
                ->lockForUpdate()
                ->findOrFail($variant->id);

            $previousStock = $locked->stock_quantity;
            $locked->increment('stock_quantity', $quantity);
            $newStock = $locked->fresh()->stock_quantity;

            $movementType = InventoryMovementType::query()
                ->firstOrCreate(['name' => 'restock']);

            // Create or update lot for contact lenses
            $lotId = null;
            if ($isContactLens) {
                $lot = InventoryLot::query()
                    ->where('product_variant_id', $locked->id)
                    ->where('lot_number', $lotNumber)
                    ->first();

                if ($lot !== null) {
                    $lot->increment('quantity_on_hand', $quantity);
                    $lot->increment('received_quantity', $quantity);
                    $lotId = $lot->id;
                } else {
                    $lot = InventoryLot::create([
                        'product_variant_id' => $locked->id,
                        'lot_number' => $lotNumber,
                        'expires_on' => $expiresOn,
                        'received_quantity' => $quantity,
                        'quantity_on_hand' => $quantity,
                        'received_at' => now(),
                        'received_by' => $receiver->id,
                        'source_reference' => $sourceReference,
                    ]);
                    $lotId = $lot->id;
                }
            }

            $movement = InventoryMovement::query()->create([
                'product_variant_id' => $locked->id,
                'inventory_lot_id' => $lotId,
                'inventory_movement_type_id' => $movementType->id,
                'quantity_change' => $quantity,
                'previous_stock' => $previousStock,
                'new_stock' => $newStock,
                'created_by' => $receiver->id,
                'notes' => $notes,
            ]);

            return $movement;
        });
    }

    private function validateLotData(string $lotNumber, string $expiresOn): void
    {
        $validator = Validator::make(
            ['lot_number' => $lotNumber, 'expires_on' => $expiresOn],
            [
                'lot_number' => ['required', 'string', 'max:50'],
                'expires_on' => ['required', 'date', 'after:today'],
            ],
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
