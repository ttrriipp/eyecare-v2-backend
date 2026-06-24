<?php

namespace App\Actions\Billing;

use App\Actions\Audit\CreateAuditLog;
use App\Models\Billing;
use App\Models\BillingStatus;
use App\Models\Order;
use App\Notifications\BillingIssued;
use Illuminate\Validation\ValidationException;

class GenerateBillingForOrder
{
    /**
     * Generate a billing record for a confirmed order.
     *
     * Throws a ValidationException if the order is not confirmed or a billing
     * already exists for this order.
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

        $issuedStatus = BillingStatus::query()->where('name', 'issued')->firstOrFail();

        $billing = Billing::query()->create([
            'billable_type' => Order::class,
            'billable_id' => $order->id,
            'billing_status_id' => $issuedStatus->id,
            'total_amount' => $order->total_amount,
            'amount_paid' => '0.00',
            'balance_due' => $order->total_amount,
            'issued_at' => now(),
        ]);

        app(CreateAuditLog::class)->handle(
            subject: $billing,
            action: 'billing.generated',
            metadata: ['order_id' => $order->id, 'total_amount' => (string) $order->total_amount],
        );

        $order->customer->notify(new BillingIssued($billing));

        return $billing;
    }
}
