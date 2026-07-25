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
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latestRevision = $this->latestRevision;

        return [
            'id' => $this->id,
            'quotation_number' => $this->quotation_number,
            'status' => $this->status?->value,
            'valid_until' => $this->valid_until?->toDateString(),
            'notes' => $this->notes,
            'revision' => $latestRevision ? [
                'revision_number' => $latestRevision->revision_number,
                'subtotal' => $latestRevision->subtotal,
                'discount_amount' => $latestRevision->discount_amount,
                'total' => $latestRevision->total,
                'items' => $latestRevision->items->map(fn ($item): array => [
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount,
                ]),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
