<?php

namespace App\Actions\Inventory;

use App\Models\InventoryLot;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReconcileContactLensLots
{
    /**
     * Reconcile existing contact-lens stock across real lots.
     *
     * Allocations must sum exactly to the locked current aggregate
     * and must not create a second stock increase.
     */
    public function handle(
        ProductVariant $variant,
        array $lotAllocations,
        User $admin,
    ): void {
        if (! $admin->isAdmin()) {
            throw ValidationException::withMessages([
                'admin' => ['Only an admin can reconcile contact-lens lots.'],
            ]);
        }

        if ($variant->product->product_type !== 'contact_lens') {
            throw ValidationException::withMessages([
                'variant' => ['Only contact-lens variants require lot reconciliation.'],
            ]);
        }

        DB::transaction(function () use ($variant, $lotAllocations, $admin): void {
            $locked = ProductVariant::query()
                ->lockForUpdate()
                ->findOrFail($variant->id);

            $currentStock = $locked->stock_quantity;

            // Validate allocations sum exactly to current stock
            $allocatedTotal = collect($lotAllocations)->sum('quantity');

            if ($allocatedTotal !== $currentStock) {
                throw ValidationException::withMessages([
                    'allocations' => ["Allocations ({$allocatedTotal}) must sum exactly to current stock ({$currentStock})."],
                ]);
            }

            // Validate each allocation
            foreach ($lotAllocations as $index => $allocation) {
                if (empty($allocation['lot_number'])) {
                    throw ValidationException::withMessages([
                        "allocations.{$index}.lot_number" => ['Lot number is required.'],
                    ]);
                }

                if (empty($allocation['expires_on'])) {
                    throw ValidationException::withMessages([
                        "allocations.{$index}.expires_on" => ['Expiration date is required.'],
                    ]);
                }

                if ($allocation['quantity'] <= 0) {
                    throw ValidationException::withMessages([
                        "allocations.{$index}.quantity" => ['Quantity must be positive.'],
                    ]);
                }

                // Check for duplicate lot numbers
                $duplicateCount = collect($lotAllocations)
                    ->filter(fn ($a) => $a['lot_number'] === $allocation['lot_number'])
                    ->count();

                if ($duplicateCount > 1) {
                    throw ValidationException::withMessages([
                        "allocations.{$index}.lot_number" => ['Duplicate lot number.'],
                    ]);
                }
            }

            // Create lots
            foreach ($lotAllocations as $allocation) {
                InventoryLot::create([
                    'product_variant_id' => $locked->id,
                    'lot_number' => $allocation['lot_number'],
                    'expires_on' => $allocation['expires_on'],
                    'received_quantity' => $allocation['quantity'],
                    'quantity_on_hand' => $allocation['quantity'],
                    'received_at' => now(),
                    'received_by' => $admin->id,
                    'source_reference' => 'Reconciliation',
                ]);
            }
        });
    }
}
