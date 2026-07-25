<?php

namespace App\Actions\JobOrders;

use App\Enums\JobOrderStatus;
use App\Models\JobOrder;
use App\Models\ProductVariant;
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

    public function handle(JobOrder $jobOrder, string $statusName): JobOrder
    {
        $currentStatus = $jobOrder->status->value;
        $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];

        if (! in_array($statusName, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition job order from '{$currentStatus}' to '{$statusName}'."],
            ]);
        }

        $newStatus = JobOrderStatus::from($statusName);

        return DB::transaction(function () use ($jobOrder, $newStatus): JobOrder {
            $attributes = ['status' => $newStatus];

            match ($newStatus) {
                JobOrderStatus::InProgress => $attributes['started_at'] = now(),
                JobOrderStatus::ReadyForDispensing => $attributes['ready_at'] = now(),
                JobOrderStatus::Dispensed => $attributes['dispensed_at'] = now(),
                JobOrderStatus::Cancelled => $attributes['cancelled_at'] = now(),
                default => null,
            };

            $jobOrder->update($attributes);

            // Reverse inventory on cancellation if order was committed
            if ($newStatus === JobOrderStatus::Cancelled) {
                $this->reverseInventory($jobOrder);
            }

            return $jobOrder->fresh();
        });
    }

    private function reverseInventory(JobOrder $jobOrder): void
    {
        foreach ($jobOrder->items as $item) {
            if ($item->product_variant_id === null) {
                continue;
            }

            ProductVariant::query()
                ->whereKey($item->product_variant_id)
                ->lockForUpdate()
                ->increment('stock_quantity', $item->quantity);
        }
    }
}
