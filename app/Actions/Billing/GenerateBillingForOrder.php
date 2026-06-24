<?php

namespace App\Actions\Billing;

use App\Actions\Audit\CreateAuditLog;
use App\Models\Billing;
use App\Models\BillingItem;
use App\Models\BillingStatus;
use App\Models\Order;
use App\Notifications\BillingIssued;
use Illuminate\Validation\ValidationException;

class GenerateBillingForOrder
{
    /**
     * Generate a billing (invoice) for a confirmed order, with product line items.
     *
     * Throws ValidationException if the order is not confirmed or a billing already exists.
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
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'discount_type_id' => $order->discount_type_id,
            'discount_amount' => $order->discount_amount,
            'subtotal' => $order->subtotal,
            'billing_status_id' => $issuedStatus->id,
            'total_amount' => $order->total_amount,
            'amount_paid' => '0.00',
            'balance_due' => $order->total_amount,
            'issued_at' => now(),
        ]);

        // Create billing items from order items
        $order->load('items');
        foreach ($order->items as $orderItem) {
            $lineAmount = bcadd(
                bcmul((string) ($orderItem->unit_price + ($orderItem->lens_type_price ?? 0)), (string) $orderItem->quantity, 2),
                '0',
                2
            );

            BillingItem::query()->create([
                'billing_id' => $billing->id,
                'type' => 'product',
                'description' => $orderItem->product_name.' — '.$orderItem->variant_name,
                'quantity' => $orderItem->quantity,
                'unit_price' => bcadd((string) $orderItem->unit_price, (string) ($orderItem->lens_type_price ?? 0), 2),
                'amount' => $lineAmount,
                'order_item_id' => $orderItem->id,
            ]);
        }

        app(CreateAuditLog::class)->handle(
            subject: $billing,
            action: 'billing.generated',
            metadata: ['order_id' => $order->id, 'total_amount' => (string) $order->total_amount],
        );

        $order->customer->notify(new BillingIssued($billing));

        return $billing;
    }
}
