<?php

namespace App\Actions\Billing;

use App\Models\Billing;
use App\Models\BillingStatus;
use App\Models\Order;
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

        $draftStatus = BillingStatus::query()->where('name', 'draft')->firstOrFail();

        return Billing::query()->create([
            'order_id' => $order->id,
            'billing_status_id' => $draftStatus->id,
            'total_amount' => $order->total_amount,
            'amount_paid' => '0.00',
            'balance_due' => $order->total_amount,
        ]);
    }
}
