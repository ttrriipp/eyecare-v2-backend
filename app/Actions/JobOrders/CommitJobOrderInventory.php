<?php

namespace App\Actions\JobOrders;

use App\Enums\JobOrderStatus;
use App\Models\JobOrder;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommitJobOrderInventory
{
    public function handle(JobOrder $jobOrder): void
    {
        if ($jobOrder->status !== JobOrderStatus::Queued) {
            throw ValidationException::withMessages([
                'job_order' => ['Only queued job orders can commit inventory.'],
            ]);
        }

        DB::transaction(function () use ($jobOrder): void {
            foreach ($jobOrder->items as $item) {
                if ($item->product_variant_id === null) {
                    continue;
                }

                $variant = ProductVariant::query()
                    ->whereKey($item->product_variant_id)
                    ->lockForUpdate()
                    ->first();

                if ($variant === null || $variant->stock_quantity < $item->quantity) {
                    throw ValidationException::withMessages([
                        'items' => ["Insufficient stock for variant {$item->product_variant_id}."],
                    ]);
                }

                $variant->decrement('stock_quantity', $item->quantity);
            }
        });
    }
}
