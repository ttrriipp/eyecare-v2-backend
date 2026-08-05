<?php

namespace App\Http\Resources;

use App\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Quotation
 */
class QuotationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Maintains backward compatibility by composing a single current
     * `revision` object from direct quotation fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quotation_number' => $this->quotation_number,
            'status' => $this->status?->value,
            'valid_until' => $this->valid_until?->toDateString(),
            'notes' => $this->notes,
            'revision' => [
                'revision_number' => 1,
                'subtotal' => $this->subtotal,
                'discount_amount' => $this->discount_amount,
                'total' => $this->total,
                'items' => $this->items->map(fn ($item): array => [
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount,
                    'item_type' => $item->item_type?->value,
                    'product_variant_id' => $item->product_variant_id,
                    'lens_category_id' => $item->lens_category_id,
                    'service_id' => $item->service_id,
                ]),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
