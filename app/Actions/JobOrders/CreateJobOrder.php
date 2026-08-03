<?php

namespace App\Actions\JobOrders;

use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Enums\TransactionItemType;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementType;
use App\Models\JobOrder;
use App\Models\JobOrderItem;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateJobOrder
{
    public function handle(Quotation $quotation, User $creator): JobOrder
    {
        if (! in_array($creator->role?->name, ['admin', 'staff'], true)) {
            throw ValidationException::withMessages([
                'creator' => ['Only clinic staff can create a job order.'],
            ]);
        }

        if ($quotation->status !== QuotationStatus::Accepted) {
            throw ValidationException::withMessages([
                'quotation' => ['Only accepted quotations can create job orders.'],
            ]);
        }

        return DB::transaction(function () use ($quotation, $creator): JobOrder {
            $quotation = Quotation::query()
                ->whereKey($quotation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (JobOrder::query()->where('quotation_id', $quotation->id)->exists()) {
                throw ValidationException::withMessages([
                    'quotation' => ['A job order already exists for this quotation.'],
                ]);
            }

            if (JobOrder::query()->where('eyewear_key', $quotation->eyewear_key)->exists()) {
                throw ValidationException::withMessages([
                    'quotation' => ['A job order already exists for this aggregate.'],
                ]);
            }

            $jobOrder = JobOrder::query()->create([
                'patient_id' => $quotation->patient_id,
                'encounter_id' => $quotation->encounter_id,
                'prescription_id' => $quotation->prescription_id,
                'quotation_id' => $quotation->id,
                'status' => JobOrderStatus::Queued,
                'total_amount' => $quotation->total,
                'eyewear_key' => $quotation->eyewear_key,
            ]);

            $commitmentType = InventoryMovementType::query()
                ->firstOrCreate(['name' => 'order_commitment']);

            // Snapshot Product items only and commit inventory
            $productItems = $quotation->items()
                ->where('item_type', TransactionItemType::Product)
                ->get();

            foreach ($productItems as $item) {
                JobOrderItem::query()->create([
                    'job_order_id' => $jobOrder->id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount,
                    'product_variant_id' => $item->product_variant_id,
                    'lens_category_id' => $item->lens_category_id,
                    'item_type' => TransactionItemType::Product,
                ]);

                // Commit stock for stock-managed items
                if ($item->product_variant_id !== null) {
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
                        'created_by' => $creator->id,
                        'notes' => "Commitment for job order #{$jobOrder->job_order_number}",
                    ]);
                }
            }

            return $jobOrder->load('items');
        });
    }
}
