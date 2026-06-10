<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Prescription;
use Illuminate\Validation\ValidationException;

class UpdateOrderStatus
{
    /**
     * Allowed status transitions: current → permitted next statuses.
     *
     * @var array<string, string[]>
     */
    private const ALLOWED_TRANSITIONS = [
        'requested' => ['under_review', 'cancelled'],
        'under_review' => ['confirmed', 'cancelled'],
        'confirmed' => ['preparing', 'cancelled'],
        'preparing' => ['ready_for_pickup', 'cancelled'],
        'ready_for_pickup' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function handle(Order $order, string $statusName): Order
    {
        $currentStatus = $order->status->name;
        $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];

        if (! in_array($statusName, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition order from '{$currentStatus}' to '{$statusName}'."],
            ]);
        }

        if ($statusName === 'confirmed' && ! $order->is_non_prescription) {
            $hasPrescription = Prescription::query()
                ->where('customer_id', $order->customer_id)
                ->exists();

            if (! $hasPrescription) {
                throw ValidationException::withMessages([
                    'status' => ['A prescription is required before confirming this order.'],
                ]);
            }
        }

        $status = OrderStatus::query()->where('name', $statusName)->firstOrFail();

        $attributes = [
            'order_status_id' => $status->id,
        ];

        if ($statusName === 'confirmed') {
            $attributes['confirmed_at'] = now();
        }

        if ($statusName === 'completed') {
            $attributes['completed_at'] = now();
        }

        $order->update($attributes);

        return $order->fresh(['status', 'items']);
    }
}
