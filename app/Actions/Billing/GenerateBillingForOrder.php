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
     * Generate or update a billing (invoice) for a confirmed order.
     *
     * Uses GetOrCreateBilling to find an existing billing for the same appointment,
     * then adds product line items via AddOrderItemsToBilling.
     */
    public function handle(Order $order): Billing
    {
        if ($order->status->name !== 'confirmed') {
            throw ValidationException::withMessages([
                'order' => ['Billing can only be generated for confirmed orders.'],
            ]);
        }

        if ($order->billing()->exists()) {
            throw ValidationException::withMessages([
                'order' => ['A billing record already exists for this order.'],
            ]);
        }

        $billing = $this->getOrCreate->handle(
            customerId: $order->customer_id,
            appointmentId: $order->appointment_id,
        );

        $isNewBilling = $billing->wasRecentlyCreated;

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
