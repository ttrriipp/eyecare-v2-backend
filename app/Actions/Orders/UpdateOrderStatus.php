<?php

namespace App\Actions\Orders;

use App\Actions\Audit\CreateAuditLog;
use App\Actions\Billing\GenerateBillingForOrder;
use App\Actions\Inventory\RecordInventoryMovement;
use App\Models\BillingStatus;
use App\Models\NotificationStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Prescription;
use App\Models\SmsNotification;
use App\Models\User;
use App\Notifications\OrderStatusChanged;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class UpdateOrderStatus
{
    /**
     * Allowed status transitions: current → permitted next statuses.
     *
     * @var array<string, string[]>
     */
    public const ALLOWED_TRANSITIONS = [
        'requested' => ['confirmed', 'cancelled'],
        'confirmed' => ['processing', 'cancelled'],
        'processing' => ['ready_for_pickup', 'cancelled'],
        'ready_for_pickup' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    /**
     * @var array<string, string>
     */
    private const SMS_EVENTS = [
        'confirmed' => 'order_confirmed',
        'processing' => 'order_processing',
        'ready_for_pickup' => 'order_ready',
        'completed' => 'order_completed',
        'cancelled' => 'order_cancelled',
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

        if ($statusName === 'processing') {
            $this->guardPrescription($order);
            $this->guardLensAssignment($order);
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

        if ($statusName === 'processing') {
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
                // Billing failure must not block processing — log silently
                logger()->error("Failed to auto-generate billing for order #{$order->id}");
            }

            $order->loadMissing('customer');
            $recipients = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('name', ['staff', 'admin']))
                ->get();

            Notification::make()
                ->title('Order Processing')
                ->body("Order {$order->order_number} by {$order->customer->name} is now being processed.")
                ->success()
                ->sendToDatabase($recipients);
        }

        if ($statusName === 'cancelled' && in_array($currentStatus, ['processing', 'ready_for_pickup'], true)) {
            $this->restoreInventory($order);

            // Auto-void the billing since the order is cancelled
            $order->billing?->update([
                'billing_status_id' => BillingStatus::query()->where('name', 'voided')->value('id'),
            ]);
        }

        app(CreateAuditLog::class)->handle(
            subject: $order,
            action: 'order.status_changed',
            metadata: ['from' => $currentStatus, 'to' => $statusName],
        );

        if (array_key_exists($statusName, self::SMS_EVENTS)) {
            $this->createSmsNotification($order, self::SMS_EVENTS[$statusName]);
        }

        $freshOrder = $order->fresh(['status', 'items']);
        $freshOrder->customer->notify(new OrderStatusChanged($freshOrder));

        return $freshOrder;
    }

    private function guardPrescription(Order $order): void
    {
        if ($order->is_non_prescription) {
            return;
        }

        $hasPrescription = Prescription::query()
            ->where('customer_id', $order->customer_id)
            ->exists();

        if (! $hasPrescription) {
            throw ValidationException::withMessages([
                'status' => ['A prescription is required before processing this order.'],
            ]);
        }
    }

    private function guardLensAssignment(Order $order): void
    {
        $order->loadMissing('items');
        $hasUnassignedLens = $order->items->contains(
            fn ($item) => $item->lens_category_id !== null && $item->lens_product_variant_id === null
        );

        if ($hasUnassignedLens) {
            throw ValidationException::withMessages([
                'status' => ['All lens items must have a lens product assigned before processing.'],
            ]);
        }
    }

    private function createSmsNotification(Order $order, string $event): void
    {
        $order->loadMissing('customer');
        $queuedStatus = NotificationStatus::query()->where('name', 'queued')->firstOrFail();

        $message = match ($event) {
            'order_confirmed' => "Your order {$order->order_number} has been confirmed.",
            'order_processing' => "Your order {$order->order_number} is now being processed.",
            'order_ready' => "Your order {$order->order_number} is ready for pickup.",
            'order_completed' => "Your order {$order->order_number} has been completed. Thank you!",
            'order_cancelled' => "Your order {$order->order_number} has been cancelled.",
            default => "Your order {$order->order_number} status has been updated.",
        };

        SmsNotification::query()->create([
            'order_id' => $order->id,
            'notification_status_id' => $queuedStatus->id,
            'event' => $event,
            'recipient' => $order->customer->phone ?? $order->customer->email,
            'message' => $message,
        ]);
    }

    /**
     * Deduct stock for each order item when an order moves to processing.
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
                    notes: "Frame deducted on order #{$order->order_number} processing.",
                );
            }

            if ($item->lens_product_variant_id !== null) {
                $recorder->handle(
                    variant: $item->lensProductVariant,
                    quantityChange: -$item->quantity,
                    type: 'order_commitment',
                    orderId: $order->id,
                    notes: "Lens deducted on order #{$order->order_number} processing.",
                );
            }
        }
    }

    /**
     * Restore stock for each order item when a processing (or later) order is cancelled.
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
