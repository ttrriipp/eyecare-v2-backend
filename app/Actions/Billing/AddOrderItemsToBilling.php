<?php

namespace App\Actions\Billing;

use App\Models\Billing;
use App\Models\BillingItem;
use App\Models\Order;
use Illuminate\Validation\ValidationException;

class AddOrderItemsToBilling
{
    /**
     * Add product line items from an order to a billing.
     * Sets order_id on the billing, copies discount, recalculates totals.
     *
     * Throws if this order's items are already on a billing.
     */
    public function handle(Billing $billing, Order $order): Billing
    {
        if (BillingItem::query()->where('billing_id', $billing->id)->whereNotNull('order_item_id')
            ->whereHas('orderItem', fn ($q) => $q->where('order_id', $order->id))->exists()) {
            throw ValidationException::withMessages([
                'order' => ['Order items are already on this billing.'],
            ]);
        }

        $order->load('items');

        foreach ($order->items as $orderItem) {
            $unitPrice = bcadd((string) $orderItem->unit_price, (string) ($orderItem->lens_type_price ?? 0), 2);
            $lineAmount = bcmul($unitPrice, (string) $orderItem->quantity, 2);

            BillingItem::query()->create([
                'billing_id' => $billing->id,
                'type' => 'product',
                'description' => $orderItem->product_name.' — '.$orderItem->variant_name,
                'quantity' => $orderItem->quantity,
                'unit_price' => $unitPrice,
                'amount' => $lineAmount,
                'order_item_id' => $orderItem->id,
            ]);
        }

        // Set order_id only if not already linked to a different order (multi-order
        // encounters must not clobber an earlier order's billing() lookup — the
        // per-item association is already tracked via billing_items.order_item_id).
        $newSubtotal = $billing->items()->sum('amount');
        $discountAmount = $billing->discount_amount ?? '0.00';
        $newTotal = bcsub((string) $newSubtotal, (string) $discountAmount, 2);

        $updates = [
            'subtotal' => $newSubtotal,
            'total_amount' => $newTotal,
            'balance_due' => bcsub((string) $newTotal, (string) $billing->amount_paid, 2),
        ];

        if ($billing->order_id === null) {
            $updates['order_id'] = $order->id;
        }

        $billing->update($updates);

        return $billing->fresh();
    }
}
