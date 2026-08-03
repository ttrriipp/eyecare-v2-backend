<?php

namespace App\Http\Resources\Api;

use App\Models\JobOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobOrder
 */
class OpticalOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->job_order_number,
            'status' => $this->status->value,
            'fulfillment_mode' => $this->fulfillment_mode,
            'total_amount' => number_format((float) $this->total_amount, 2, '.', ''),
            'started_at' => $this->started_at?->toIso8601String(),
            'ready_at' => $this->ready_at?->toIso8601String(),
            'dispensed_at' => $this->dispensed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'source_quotation' => $this->quotation ? [
                'id' => $this->quotation->id,
                'quotation_number' => $this->quotation->quotation_number,
            ] : null,
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
                'amount' => number_format((float) $item->amount, 2, '.', ''),
            ]),
            'payment_summary' => $this->when($this->billingRecord, function () {
                $billing = $this->billingRecord;

                return [
                    'status' => $billing->status->getLabel(),
                    'total_amount' => number_format((float) $billing->total_amount, 2, '.', ''),
                    'amount_paid' => number_format((float) $billing->amount_paid, 2, '.', ''),
                    'balance_due' => number_format((float) $billing->balance_due, 2, '.', ''),
                    'payment_due_date' => $billing->payment_due_date?->format('Y-m-d'),
                ];
            }),
        ];
    }
}
