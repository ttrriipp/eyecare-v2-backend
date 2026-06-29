<?php

namespace App\Http\Resources;

use App\Models\Billing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Billing */
class BillingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'billing_number' => $this->billing_number,
            'status' => $this->status->name,
            'total_amount' => $this->total_amount,
            'discount_amount' => $this->discount_amount,
            'subtotal' => $this->subtotal,
            'amount_paid' => $this->amount_paid,
            'balance_due' => $this->balance_due,
            'issued_at' => $this->issued_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'type' => $item->type,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'amount' => $item->amount,
            ])),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'status' => $payment->status->name,
                'method' => $payment->paymentMethod?->name,
                'reference_number' => $payment->reference_number,
                'paid_at' => $payment->paid_at?->toISOString(),
            ])),
        ];
    }
}
