<?php

namespace App\Actions\Inventory;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WriteOffFrameStock
{
    /**
     * Write off damaged frame stock from a specific non-expiring batch.
     *
     * @throws AuthorizationException when the actor is not panel staff.
     * @throws ValidationException when the batch or quantity is invalid.
     */
    public function handle(
        ProductVariant $variant,
        int $quantity,
        int $inventoryLotId,
        User $actor,
        string $notes,
    ): InventoryMovement {
        if (! $actor->hasPanelRole()) {
            throw new AuthorizationException('Only panel staff can write off inventory.');
        }

        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Quantity must be positive.'],
            ]);
        }

        $notes = trim($notes);

        if ($notes === '') {
            throw ValidationException::withMessages([
                'notes' => ['A damage reason is required.'],
            ]);
        }

        $variant->load('product');

        if (! $variant->isFrame()) {
            throw ValidationException::withMessages([
                'product_variant_id' => ['Only frame variants use batch-specific write-offs.'],
            ]);
        }

        return DB::transaction(function () use (
            $variant,
            $quantity,
            $inventoryLotId,
            $actor,
            $notes,
        ): InventoryMovement {
            $lockedVariant = ProductVariant::query()
                ->lockForUpdate()
                ->findOrFail($variant->id);
            $lockedVariant->load('product');

            if (! $lockedVariant->isFrame()) {
                throw ValidationException::withMessages([
                    'product_variant_id' => ['Only frame variants use batch-specific write-offs.'],
                ]);
            }

            $batch = InventoryLot::query()
                ->whereKey($inventoryLotId)
                ->where('product_variant_id', $lockedVariant->id)
                ->whereNull('expires_on')
                ->lockForUpdate()
                ->first();

            if ($batch === null) {
                throw ValidationException::withMessages([
                    'inventory_lot_id' => ['Select a batch belonging to this frame variant.'],
                ]);
            }

            if ($batch->quantity_on_hand < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => ['The selected batch does not have enough stock to write off.'],
                ]);
            }

            $previousStock = (int) $lockedVariant->stock_quantity;

            if ($previousStock < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => ['The variant aggregate does not have enough stock to write off.'],
                ]);
            }

            $newStock = $previousStock - $quantity;
            $batch->update(['quantity_on_hand' => $batch->quantity_on_hand - $quantity]);
            $lockedVariant->update(['stock_quantity' => $newStock]);

            $movement = InventoryMovement::query()->create([
                'product_variant_id' => $lockedVariant->id,
                'inventory_lot_id' => $batch->id,
                'inventory_movement_type_id' => InventoryMovementType::query()
                    ->firstOrCreate(['name' => 'damaged'])->id,
                'quantity_change' => -$quantity,
                'previous_stock' => $previousStock,
                'new_stock' => $newStock,
                'created_by' => $actor->id,
                'notes' => $notes,
            ]);

            app(CreateAuditLog::class)->handle(
                subject: $movement,
                action: AuditEvent::InventoryMovementRecorded,
                metadata: [
                    'type' => 'damaged',
                    'quantity_change' => -$quantity,
                    'variant_id' => $lockedVariant->id,
                    'inventory_lot_id' => $batch->id,
                    'batch_number' => $batch->lot_number,
                ],
                actorId: $actor->id,
            );

            $freshVariant = $lockedVariant->fresh(['product']);

            if ($freshVariant->low_stock_threshold > 0
                && $freshVariant->stock_quantity <= $freshVariant->low_stock_threshold) {
                $this->notifyLowStock($freshVariant);
            }

            return $movement;
        });
    }

    private function notifyLowStock(ProductVariant $variant): void
    {
        $recipients = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['staff', 'admin']))
            ->get();

        Notification::make()
            ->title('Low Stock Alert')
            ->body("{$variant->product->name} — {$variant->name} is low on stock ({$variant->stock_quantity} remaining).")
            ->warning()
            ->sendToDatabase($recipients);
    }
}
