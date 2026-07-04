<?php

namespace App\Actions\Billing;

use App\Actions\Audit\CreateAuditLog;
use App\Models\Billing;
use App\Models\Order;
use App\Notifications\BillingIssued;
use Illuminate\Validation\ValidationException;

class GenerateBillingForOrder
{
    public function __construct(
        private readonly GetOrCreateBilling $getOrCreate,
        private readonly AddOrderItemsToBilling $addItems,
    ) {}

    /**
     * Generate or update a billing (invoice) for an order moving to processing.
     *
     * If the order was pre-linked to an existing billing (via the "Create Order"
     * action on a billing), items are attached to that billing instead of
     * creating or reusing one. Otherwise falls back to GetOrCreateBilling for
     * encounter grouping by appointment.
     */
    public function handle(Order $order): Billing
    {
        if ($order->status->name !== 'processing') {
            throw ValidationException::withMessages([
                'order' => ['Billing can only be generated once an order is processing.'],
            ]);
        }

        if ($order->billing()->exists()) {
            throw ValidationException::withMessages([
                'order' => ['A billing record already exists for this order.'],
            ]);
        }

        if ($order->billing_id !== null) {
            $billing = Billing::query()->findOrFail($order->billing_id);
            $isNewBilling = false;
        } else {
            $billing = $this->getOrCreate->handle(
                customerId: $order->customer_id,
                appointmentId: $order->appointment_id,
            );
            $isNewBilling = $billing->wasRecentlyCreated;
        }

        $billing = $this->addItems->handle($billing, $order);

        app(CreateAuditLog::class)->handle(
            subject: $billing,
            action: 'billing.generated',
            metadata: ['order_id' => $order->id, 'total_amount' => (string) $order->total_amount],
        );

        if ($isNewBilling) {
            $order->customer->notify(new BillingIssued($billing));
        }

        return $billing;
    }
}
