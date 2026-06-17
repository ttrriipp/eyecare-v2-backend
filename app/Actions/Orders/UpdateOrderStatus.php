<?php

namespace App\Actions\Orders;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Inventory\RecordInventoryMovement;
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

    public function handle(Order $order, string $statusName, ?int $discountTypeId = null, ?float $customDiscountAmount = null): Order
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

            // Validate and compute discount before committing the status change.
            if ($discountTypeId !== null) {
                $discountAttributes = app(ApplyDiscount::class)->computeAttributes($order, $discountTypeId, $customDiscountAmount);
                $attributes = array_merge($attributes, $discountAttributes);
            }
        }

        if ($statusName === 'completed') {
            $attributes['completed_at'] = now();
        }

        $order->update($attributes);

        if ($statusName === 'confirmed') {
            $this->deductInventory($order);
        }

        if ($statusName === 'cancelled' && $currentStatus === 'confirmed') {
            $this->restoreInventory($order);
        }

        app(CreateAuditLog::class)->handle(
            subject: $order,
            action: 'order.status_changed',
            metadata: ['from' => $currentStatus, 'to' => $statusName],
        );

        return $order->fresh(['status', 'items']);
    }

    /**
     * Deduct stock for each order item when an order is confirmed.
     */
    private function deductInventory(Order $order): void
    {
        $recorder = app(RecordInventoryMovement::class);
        $order->loadMissing('items.productVariant');

        foreach ($order->items as $item) {
            if ($item->product_variant_id === null) {
                continue;
            }

            $recorder->handle(
                variant: $item->productVariant,
                quantityChange: -$item->quantity,
                type: 'order_commitment',
                orderId: $order->id,
                notes: "Deducted on order #{$order->order_number} confirmation.",
            );
        }
    }

    /**
     * Restore stock for each order item when a confirmed order is cancelled.
     */
    private function restoreInventory(Order $order): void
    {
        $recorder = app(RecordInventoryMovement::class);
        $order->loadMissing('items.productVariant');

        foreach ($order->items as $item) {
            if ($item->product_variant_id === null) {
                continue;
            }

            $recorder->handle(
                variant: $item->productVariant,
                quantityChange: $item->quantity,
                type: 'order_reversal',
                orderId: $order->id,
                notes: "Restored on order #{$order->order_number} cancellation.",
            );
        }
    }
}
