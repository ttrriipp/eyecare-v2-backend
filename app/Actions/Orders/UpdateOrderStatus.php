<?php

namespace App\Actions\Orders;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Billing\GenerateBillingForOrder;
use App\Actions\Inventory\RecordInventoryMovement;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Prescription;
use App\Notifications\OrderStatusChanged;
use Illuminate\Validation\ValidationException;

class UpdateOrderStatus
{
    /**
     * Allowed status transitions: current → permitted next statuses.
     *
     * @var array<string, string[]>
     */
    private const ALLOWED_TRANSITIONS = [
        'requested' => ['confirmed', 'cancelled'],
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
            try {
                $this->deductInventory($order);
            } catch (\RuntimeException $e) {
                // Roll back the status change and surface a user-friendly error
                $order->update(['order_status_id' => OrderStatus::query()->where('name', $currentStatus)->value('id')]);

                throw ValidationException::withMessages([
                    'status' => [$e->getMessage()],
                ]);
            }

            try {
                app(GenerateBillingForOrder::class)->handle($order->fresh());
            } catch (\Throwable) {
                // Billing failure must not block confirmation — log silently
                logger()->error("Failed to auto-generate billing for order #{$order->id}");
            }
        }
        if ($statusName === 'cancelled' && $currentStatus === 'confirmed') {
            $this->restoreInventory($order);
        }

        app(CreateAuditLog::class)->handle(
            subject: $order,
            action: 'order.status_changed',
            metadata: ['from' => $currentStatus, 'to' => $statusName],
        );

        $freshOrder = $order->fresh(['status', 'items']);
        $freshOrder->customer->notify(new OrderStatusChanged($freshOrder));

        return $freshOrder;
    }

    /**
     * Deduct stock for each order item when an order is confirmed.
     * Deducts both frame variant and lens product variant (if assigned).
     */
    private function deductInventory(Order $order): void
    {
        $recorder = app(RecordInventoryMovement::class);
        $order->loadMissing('items.productVariant', 'items.lensProductVariant');

        foreach ($order->items as $item) {
            if ($item->product_variant_id !== null) {
                $recorder->handle(
                    variant: $item->productVariant,
                    quantityChange: -$item->quantity,
                    type: 'order_commitment',
                    orderId: $order->id,
                    notes: "Frame deducted on order #{$order->order_number} confirmation.",
                );
            }

            if ($item->lens_product_variant_id !== null) {
                $recorder->handle(
                    variant: $item->lensProductVariant,
                    quantityChange: -$item->quantity,
                    type: 'order_commitment',
                    orderId: $order->id,
                    notes: "Lens deducted on order #{$order->order_number} confirmation.",
                );
            }
        }
    }

    /**
     * Restore stock for each order item when a confirmed order is cancelled.
     * Restores both frame variant and lens product variant (if assigned).
     */
    private function restoreInventory(Order $order): void
    {
        $recorder = app(RecordInventoryMovement::class);
        $order->loadMissing('items.productVariant', 'items.lensProductVariant');

        foreach ($order->items as $item) {
            if ($item->product_variant_id !== null) {
                $recorder->handle(
                    variant: $item->productVariant,
                    quantityChange: $item->quantity,
                    type: 'order_reversal',
                    orderId: $order->id,
                    notes: "Frame restored on order #{$order->order_number} cancellation.",
                );
            }

            if ($item->lens_product_variant_id !== null) {
                $recorder->handle(
                    variant: $item->lensProductVariant,
                    quantityChange: $item->quantity,
                    type: 'order_reversal',
                    orderId: $order->id,
                    notes: "Lens restored on order #{$order->order_number} cancellation.",
                );
            }
        }
    }
}
